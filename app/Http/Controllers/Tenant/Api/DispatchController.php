<?php
namespace App\Http\Controllers\Tenant\Api;

use App\CoreFacturalo\Facturalo;
use App\CoreFacturalo\WS\Gre\GreDispatchService;
use App\Models\Tenant\Dispatch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DispatchController extends Controller
{
    public function __construct()
    {
        $this->middleware('input.request:dispatch,api', ['only' => ['store']]);
    }

    public function store(Request $request)
    {
        
        $request->validate([
            'delivery.address' => 'required|max:100',
            'origin.address' => 'required|max:100',
        ]);

        $fact = DB::connection('tenant')->transaction(function () use($request) {
            $facturalo = new Facturalo();
            $facturalo->save($request->all());
            $facturalo->createXmlUnsigned();
            $facturalo->signXmlUnsigned();
            $facturalo->updateHash();
            $facturalo->createPdf();
            $facturalo->sendEmail();

            return $facturalo;
        });

        $document = $fact->getDocument();

        // Sending happens after commit: a SUNAT/network failure must not roll back the saved guide.
        if ($request->input('actions.send_xml_signed', true)) {
            $sunat = (new GreDispatchService())->send($document);
        } else {
            $sunat = ['sent' => false, 'skipped' => true, 'message' => 'Guía registrada sin enviar a SUNAT (send_xml_signed desactivado).'];
        }
        $document = Dispatch::find($document->id);
        $response = [
            'sent' => $sunat['sent'],
            'code' => optional($document->soap_shipping_response)->code,
            'description' => $sunat['message'],
            'notes' => [],
            'ticket' => $document->ticket,
            'state_type_id' => $document->state_type_id,
            'skipped' => $sunat['skipped'],
        ];

        return [
            'success' => true,
            'data' => [
                'number' => $document->number_full,
                'filename' => $document->filename,
                'external_id' => $document->external_id,
            ],
            'links' => [
                'xml' => $document->download_external_xml,
                'pdf' => $document->download_external_pdf,
                'cdr' => $document->download_external_cdr,
            ],
            'response' => $response
        ];
    }
}