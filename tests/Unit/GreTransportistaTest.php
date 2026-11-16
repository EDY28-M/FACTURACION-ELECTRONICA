<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Xml\XmlFormat;
use App\CoreFacturalo\Template;
use App\Http\Requests\Tenant\DispatchRequest;
use App\Http\Requests\Tenant\SeriesRequest;
use App\Models\Tenant\Dispatch;
use App\Models\Tenant\DispatchItem;
use App\CoreFacturalo\WS\Gre\GreClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * GRE Transportista (type 31): XML builder and server-side validation rules.
 * Pure in-memory tests: no database and nothing is sent to SUNAT.
 */
class GreTransportistaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The model only needs a connection object to resolve the date format; no query is executed.
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    }

    private function company()
    {
        return (object) [
            'number' => '20123456789',
            'name' => 'TRANSPORTES SAC',
            'trade_name' => 'TRANSPORTES',
            'mtc_registration_number' => '15001234MTC',
        ];
    }

    private function dispatch(array $overrides = [])
    {
        $dispatch = new Dispatch($overrides + [
            'document_type_id' => '31',
            'series' => 'V001',
            'number' => 5,
            'date_of_issue' => '2026-11-16',
            'time_of_issue' => '10:00:00',
            'date_of_shipping' => '2026-11-17',
            'transport_mode_type_id' => '01',
            'transfer_reason_type_id' => '01',
            'transshipment_indicator' => 0,
            'unit_type_id' => 'KGM',
            'total_weight' => 10,
            'packages_number' => 0,
            'license_plate' => 'abc-123',
            'customer' => ['identity_document_type_id' => '6', 'number' => '20100070970', 'name' => 'DESTINATARIO SAC'],
            'origin' => ['location_id' => '150101', 'address' => 'AV ORIGEN 1'],
            'delivery' => ['location_id' => '150115', 'address' => 'AV DESTINO 2'],
            'driver' => ['identity_document_type_id' => '1', 'number' => '45454545', 'license' => 'Q1', 'first_name' => 'JUAN', 'family_name' => 'PEREZ'],
            'sender' => ['identity_document_type_id' => '6', 'number' => '20512345678', 'name' => 'REMITENTE SAC'],
            'freight_payer' => 'remitente',
            'indicators' => ['total_transfer' => false, 'empty_packaging_return' => false, 'empty_vehicle_return' => false],
        ]);
        $dispatch->setRelation('reference_document', null);
        $dispatch->setRelation('items', collect([
            new DispatchItem(['item_id' => 1, 'quantity' => 1, 'item' => ['description' => 'BIEN', 'internal_id' => 'B1', 'unit_type_id' => 'NIU']]),
        ]));

        return $dispatch;
    }

    private function xml(Dispatch $dispatch)
    {
        $xml = XmlFormat::format((new Template())->xml('dispatch', $this->company(), $dispatch));
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'XML must be well-formed');
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('d', 'urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2');

        return [$xml, $xpath];
    }

    public function testCarrierXmlHasTypeCodeSenderMtcAndNoTransferReason()
    {
        [, $x] = $this->xml($this->dispatch());

        $this->assertSame('31', $x->evaluate('string(/d:DespatchAdvice/cbc:DespatchAdviceTypeCode)'));
        $this->assertSame('V001-5', $x->evaluate('string(/d:DespatchAdvice/cbc:ID)'));
        $this->assertSame('20512345678', $x->evaluate('string(//cac:Delivery/cac:Despatch/cac:DespatchParty/cac:PartyIdentification/cbc:ID)'));
        $this->assertSame('15001234MTC', $x->evaluate('string(//cac:ShipmentStage/cac:CarrierParty/cac:PartyLegalEntity/cbc:CompanyID)'));
        $this->assertSame('ABC123', $x->evaluate('string(//cac:TransportEquipment/cbc:ID)'));
        $this->assertSame('Principal', $x->evaluate('string(//cac:DriverPerson/cbc:JobTitle)'));
        $this->assertSame(0.0, $x->evaluate('count(//cbc:HandlingCode)'));
        $this->assertSame(0.0, $x->evaluate('count(//cbc:TransportModeCode)'));
        $this->assertSame('SUNAT_Envio_IndicadorPagadorFlete_Remitente', $x->evaluate('string(//cac:Shipment/cbc:SpecialInstructions)'));
        $this->assertSame(0.0, $x->evaluate('count(//cac:OriginatorCustomerParty)'));
        $this->assertSame(0.0, $x->evaluate('count(//cac:Consignment)'));
    }

    public function testCarrierXmlRendersThirdPartyPayerSubcontractorAndRelatedGuides()
    {
        [, $x] = $this->xml($this->dispatch([
            'freight_payer' => 'tercero',
            'freight_payer_party' => ['identity_document_type_id' => '6', 'number' => '20600000001', 'name' => 'PAGADOR SAC'],
            'subcontractor' => ['identity_document_type_id' => '6', 'number' => '20700000002', 'name' => 'SUBCONTRATA SAC'],
            'related_dispatches' => [['series_number' => 'T001-123', 'ruc' => '20512345678']],
            'vehicle_details' => ['tuc' => 'TUC1', 'authorization_number' => 'AUT1', 'authorization_entity_id' => '01'],
            'indicators' => ['total_transfer' => true, 'empty_packaging_return' => false, 'empty_vehicle_return' => true],
        ]));

        $this->assertSame('20600000001', $x->evaluate('string(//cac:OriginatorCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID)'));
        $this->assertSame('20700000002', $x->evaluate('string(//cac:Consignment/cac:LogisticsOperatorParty/cbc:CustomerAssignedAccountID)'));
        $this->assertSame('T001-123', $x->evaluate('string(//d:DespatchAdvice/cac:AdditionalDocumentReference/cbc:ID)'));
        $this->assertSame('09', $x->evaluate('string(//d:DespatchAdvice/cac:AdditionalDocumentReference/cbc:DocumentTypeCode)'));
        $this->assertSame('TUC1', $x->evaluate('string(//cac:TransportEquipment/cac:ShipmentDocumentReference[1]/cbc:ID)'));
        $this->assertSame('AUT1', $x->evaluate('string(//cac:TransportEquipment/cac:ShipmentDocumentReference[2]/cbc:ID)'));

        $codes = [];
        foreach ($x->query('//cac:Shipment/cbc:SpecialInstructions') as $node) {
            $codes[] = $node->textContent;
        }
        $this->assertSame([
            'SUNAT_Envio_IndicadorTrasladoTotal',
            'SUNAT_Envio_IndicadorRetornoVehiculoVacio',
            'SUNAT_Envio_IndicadorTrasporteSubcontratado',
            'SUNAT_Envio_IndicadorPagadorFlete_Tercero',
        ], $codes);
    }

    public function testRemitenteXmlIsUnaffectedByCarrierBlocks()
    {
        $dispatch = $this->dispatch([
            'document_type_id' => '09',
            'series' => 'T001',
            'transport_mode_type_id' => '02',
            'sender' => null,
        ]);
        [, $x] = $this->xml($dispatch);

        $this->assertSame('09', $x->evaluate('string(/d:DespatchAdvice/cbc:DespatchAdviceTypeCode)'));
        $this->assertSame('01', $x->evaluate('string(//cbc:HandlingCode)'));
        $this->assertSame('02', $x->evaluate('string(//cbc:TransportModeCode)'));
        $this->assertSame(0.0, $x->evaluate('count(//cac:DespatchParty)'));
        $this->assertSame(0.0, $x->evaluate('count(//cac:CarrierParty)'));
    }

    private function validData(array $overrides = [])
    {
        return $overrides + [
            'document_type_id' => '31',
            'series' => 'V001',
            'customer_id' => 1,
            'unit_type_id' => 'KGM',
            'total_weight' => 10,
            'date_of_issue' => '2026-11-16',
            'date_of_shipping' => '2026-11-16',
            'origin' => ['location_id' => '150101', 'address' => 'AV 1'],
            'delivery' => ['location_id' => '150115', 'address' => 'AV 2'],
            'items' => [['item_id' => 1, 'quantity' => 1]],
            'sender' => ['identity_document_type_id' => '6', 'number' => '20512345678', 'name' => 'REMITENTE'],
            'driver' => ['identity_document_type_id' => '1', 'number' => '45454545', 'license' => 'Q1', 'first_name' => 'A', 'family_name' => 'B'],
            'license_plate' => 'ABC123',
            'freight_payer' => 'remitente',
        ];
    }

    private function validate(array $data)
    {
        $request = DispatchRequest::create('/dispatches', 'POST', $data);

        return Validator::make($data, $request->rules(), $request->messages());
    }

    public function testValidCarrierPayloadPasses()
    {
        $this->assertTrue($this->validate($this->validData())->passes());
    }

    public function testCarrierRequiresVehicleDriverLicenseSenderItemsWeightAndVSeries()
    {
        $data = $this->validData([
            'series' => 'T001',
            'total_weight' => 0,
            'items' => [],
            'license_plate' => null,
            'driver' => ['identity_document_type_id' => '1', 'number' => '45454545'],
            'sender' => ['identity_document_type_id' => '6'],
        ]);
        $errors = $this->validate($data)->errors()->keys();

        foreach (['series', 'total_weight', 'items', 'license_plate', 'driver.license', 'driver.first_name', 'driver.family_name', 'sender.number', 'sender.name'] as $key) {
            $this->assertContains($key, $errors, "Expected an error for {$key}");
        }
    }

    public function testThirdPartyPayerAndSubcontractorAreConditionallyRequired()
    {
        $tercero = $this->validate($this->validData(['freight_payer' => 'tercero']))->errors()->keys();
        $this->assertContains('freight_payer_party.number', $tercero);
        $this->assertContains('freight_payer_party.name', $tercero);

        $sub = $this->validate($this->validData(['freight_payer' => 'subcontratador']))->errors()->keys();
        $this->assertContains('subcontractor.number', $sub);

        $related = $this->validate($this->validData(['related_dispatches' => [['series_number' => 'BAD']]]))->errors()->keys();
        $this->assertContains('related_dispatches.0.series_number', $related);
    }

    public function testMockedSendUsesTipo31InUrlAndZipName()
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'T', 'expires_in' => 3600])),
            new Response(200, [], json_encode(['numTicket' => '123'])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new GreClient([
            'ruc' => '20123456789', 'token_url' => 'https://token.test/v1', 'api_url' => 'https://api.test/v1',
            'client_id' => 'c', 'client_secret' => 's', 'username' => '20123456789U', 'password' => 'p',
        ], new Client(['handler' => $stack]), new Repository(new ArrayStore()));

        $zip = 'zip-bytes';
        $client->send('20123456789-31-V001-5', $zip);

        $request = $history[1]['request'];
        $this->assertStringEndsWith('/contribuyente/gem/comprobantes/20123456789-31-V001-5', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('20123456789-31-V001-5.zip', $body['archivo']['nomArchivo']);
        $this->assertSame(hash('sha256', $zip), $body['archivo']['hashZip']);
    }

    public function testCarrierXmlHasSingleCarrierPartyWithResidualDispatcherFromTheForm()
    {
        // create.vue always posts a dispatcher object of nulls, even for type 31.
        [, $x] = $this->xml($this->dispatch([
            'dispatcher' => ['identity_document_type_id' => null, 'number' => null, 'name' => null, 'mtc_number' => '99999'],
        ]));

        $this->assertSame(1.0, $x->evaluate('count(//cac:CarrierParty)'));
        $this->assertSame('15001234MTC', $x->evaluate('string(//cac:CarrierParty//cbc:CompanyID)'));
    }

    private function seriesErrors($type, $number)
    {
        $data = ['document_type_id' => $type, 'number' => $number];
        $request = SeriesRequest::create('/series', 'POST', $data);
        $validator = Validator::make($data, $request->rules());
        $request->withValidator($validator);

        return $validator->errors()->keys();
    }

    public function testSeriesPrefixRulesForGreTypes()
    {
        $this->assertSame([], $this->seriesErrors('31', 'V001'));
        $this->assertSame(['number'], $this->seriesErrors('31', 'T001'));
        $this->assertSame(['number'], $this->seriesErrors('09', 'V001'));
        $this->assertSame([], $this->seriesErrors('09', 'T001'));
        $this->assertSame([], $this->seriesErrors('01', 'F001'));
    }
}
