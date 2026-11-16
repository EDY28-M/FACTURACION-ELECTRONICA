@php
    // SUNAT GRE 2.0. UBL 2.1 DespatchAdvice, CustomizationID 2.0.
    // Type 09 = Guia de Remision Remitente, type 31 = Guia de Remision Transportista (RS 000123-2022, annexes 28 and 14-b).
    $isCarrier = $document->document_type_id === '31';
    $isPublicTransport = $document->transport_mode_type_id === '01';
    $driver = $document->driver;
    $dispatcher = $document->dispatcher;

    $driverFirstName = $driver->first_name ?? null;
    $driverFamilyName = $driver->family_name ?? null;
    if (!$driverFirstName && !$driverFamilyName && !empty($driver->name ?? null)) {
        // Fallback for records that only have a single full-name value.
        $parts = preg_split('/\s+/', trim($driver->name));
        if (count($parts) >= 3) {
            $driverFamilyName = implode(' ', array_slice($parts, -2));
            $driverFirstName = implode(' ', array_slice($parts, 0, -2));
        } else {
            $driverFirstName = $parts[0];
            $driverFamilyName = $parts[1] ?? $parts[0];
        }
    }

    $related = $document->reference_document;
    $normalizePlate = function ($plate) {
        return strtoupper(preg_replace('/[\s\-]+/', '', (string) $plate));
    };
    $licensePlate = $normalizePlate($document->license_plate);
    $semitrailer = $normalizePlate($document->secondary_license_plates->semitrailer ?? '');
    $weight = number_format((float) $document->total_weight, 3, '.', '');

    $relatedGuides = [];
    $carrierIndicators = [];
    if ($isCarrier) {
        $sender = $document->sender;
        $payer = $document->freight_payer_party;
        $subcontractor = $document->subcontractor;
        $vehicle = $document->vehicle_details;
        $indicators = $document->indicators;
        $relatedGuides = array_values(array_filter((array) $document->related_dispatches, function ($row) {
            return !empty($row->series_number);
        }));
        $hasSubcontractor = !empty($subcontractor->number ?? null);
        $semitrailerTuc = $vehicle->semitrailer_tuc ?? null;

        // Order follows the SUNAT annex (fields 53 to 58).
        if (!empty($indicators->total_transfer ?? null)) {
            $carrierIndicators[] = 'SUNAT_Envio_IndicadorTrasladoTotal';
        }
        if (!empty($indicators->empty_packaging_return ?? null)) {
            $carrierIndicators[] = 'SUNAT_Envio_IndicadorRetornoVehiculoEnvaseVacio';
        }
        if (!empty($indicators->empty_vehicle_return ?? null)) {
            $carrierIndicators[] = 'SUNAT_Envio_IndicadorRetornoVehiculoVacio';
        }
        if ($document->transshipment_indicator) {
            $carrierIndicators[] = 'SUNAT_Envio_IndicadorTransbordoProgramado';
        }
        if ($hasSubcontractor) {
            $carrierIndicators[] = 'SUNAT_Envio_IndicadorTrasporteSubcontratado';
        }
        $payerSuffix = ['remitente' => 'Remitente', 'subcontratador' => 'Subcontratador', 'tercero' => 'Tercero'][$document->freight_payer] ?? null;
        if ($payerSuffix) {
            $carrierIndicators[] = 'SUNAT_Envio_IndicadorPagadorFlete_'.$payerSuffix;
        }
    }
@endphp
{!! '<'.'?xml version="1.0" encoding="utf-8" standalone="no"?'.'>' !!}
<DespatchAdvice xmlns="urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2"
                xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
                xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
                xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
                xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">
    <ext:UBLExtensions>
        <ext:UBLExtension>
            <ext:ExtensionContent/>
        </ext:UBLExtension>
    </ext:UBLExtensions>
    <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
    <cbc:CustomizationID>2.0</cbc:CustomizationID>
    <cbc:ID>{{ $document->series }}-{{ $document->number }}</cbc:ID>
    <cbc:IssueDate>{{ $document->date_of_issue->format('Y-m-d') }}</cbc:IssueDate>
    <cbc:IssueTime>{{ $document->time_of_issue }}</cbc:IssueTime>
    <cbc:DespatchAdviceTypeCode listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01">{{ $document->document_type_id }}</cbc:DespatchAdviceTypeCode>
    @if($document->observations)
    <cbc:Note>{{ $document->observations }}</cbc:Note>
    @endif
    @foreach($relatedGuides as $guide)
    <cac:AdditionalDocumentReference>
        <cbc:ID>{{ $guide->series_number }}</cbc:ID>
        <cbc:DocumentTypeCode listAgencyName="PE:SUNAT" listName="Documento relacionado al transporte" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo61">09</cbc:DocumentTypeCode>
        <cbc:DocumentType>Guía de remisión remitente</cbc:DocumentType>
        @if(!empty($guide->ruc))
        <cac:IssuerParty>
            <cac:PartyIdentification>
                <cbc:ID schemeID="6" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{ $guide->ruc }}</cbc:ID>
            </cac:PartyIdentification>
        </cac:IssuerParty>
        @endif
    </cac:AdditionalDocumentReference>
    @endforeach
    @if($related && empty($relatedGuides) && in_array($related->document_type_id, ['01', '03']))
    <cac:AdditionalDocumentReference>
        <cbc:ID>{{ $related->series }}-{{ $related->number }}</cbc:ID>
        <cbc:DocumentTypeCode listAgencyName="PE:SUNAT" listName="Documento relacionado al transporte" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo61">{{ $related->document_type_id }}</cbc:DocumentTypeCode>
        <cbc:DocumentType>{{ $related->document_type_id === '01' ? 'Factura' : 'Boleta de venta' }}</cbc:DocumentType>
        <cac:IssuerParty>
            <cac:PartyIdentification>
                <cbc:ID schemeID="6" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{ $company->number }}</cbc:ID>
            </cac:PartyIdentification>
        </cac:IssuerParty>
    </cac:AdditionalDocumentReference>
    @endif
    <cac:Signature>
        <cbc:ID>{{ config('configuration.signature_uri') }}</cbc:ID>
        <cbc:Note>{{ config('configuration.signature_note') }}</cbc:Note>
        <cac:SignatoryParty>
            <cac:PartyIdentification>
                <cbc:ID>{{ $company->number }}</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyName>
                <cbc:Name>{{ $company->trade_name ?: $company->name }}</cbc:Name>
            </cac:PartyName>
        </cac:SignatoryParty>
        <cac:DigitalSignatureAttachment>
            <cac:ExternalReference>
                <cbc:URI>#{{ config('configuration.signature_uri') }}</cbc:URI>
            </cac:ExternalReference>
        </cac:DigitalSignatureAttachment>
    </cac:Signature>
    <cac:DespatchSupplierParty>
        <cac:Party>
            <cac:PartyIdentification>
                <cbc:ID schemeID="6" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{ $company->number }}</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName>{{ $company->name }}</cbc:RegistrationName>
            </cac:PartyLegalEntity>
        </cac:Party>
    </cac:DespatchSupplierParty>
    <cac:DeliveryCustomerParty>
        <cac:Party>
            <cac:PartyIdentification>
                <cbc:ID schemeID="{{ $document->customer->identity_document_type_id }}" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{ $document->customer->number }}</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName>{{ $document->customer->name }}</cbc:RegistrationName>
            </cac:PartyLegalEntity>
        </cac:Party>
    </cac:DeliveryCustomerParty>
    @if($isCarrier && $document->freight_payer === 'tercero' && !empty($payer->number ?? null))
    <cac:OriginatorCustomerParty>
        <cac:Party>
            <cac:PartyIdentification>
                <cbc:ID schemeID="{{ $payer->identity_document_type_id ?? '' }}" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{ $payer->number }}</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName>{{ $payer->name ?? '' }}</cbc:RegistrationName>
            </cac:PartyLegalEntity>
        </cac:Party>
    </cac:OriginatorCustomerParty>
    @endif
    <cac:Shipment>
        <cbc:ID>SUNAT_Envio</cbc:ID>
        @if(!$isCarrier)
        <cbc:HandlingCode listAgencyName="PE:SUNAT" listName="Motivo de traslado" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo20">{{ $document->transfer_reason_type_id }}</cbc:HandlingCode>
        @if($document->transfer_reason_description)
        <cbc:HandlingInstructions>{{ $document->transfer_reason_description }}</cbc:HandlingInstructions>
        @endif
        @endif
        <cbc:GrossWeightMeasure unitCode="{{ $document->unit_type_id }}">{{ $weight }}</cbc:GrossWeightMeasure>
        @if(!$isCarrier && $document->packages_number)
        <cbc:TotalTransportHandlingUnitQuantity>{{ $document->packages_number }}</cbc:TotalTransportHandlingUnitQuantity>
        @endif
        @if(!$isCarrier && $document->transshipment_indicator)
        <cbc:SpecialInstructions>SUNAT_Envio_IndicadorTransbordoProgramado</cbc:SpecialInstructions>
        @endif
        @foreach($carrierIndicators as $indicator)
        <cbc:SpecialInstructions>{{ $indicator }}</cbc:SpecialInstructions>
        @endforeach
        @if($isCarrier && $hasSubcontractor)
        <cac:Consignment>
            <cbc:ID>SUNAT_Envio</cbc:ID>
            <cac:LogisticsOperatorParty>
                <cbc:CustomerAssignedAccountID schemeID="{{ $subcontractor->identity_document_type_id ?? '6' }}">{{ $subcontractor->number }}</cbc:CustomerAssignedAccountID>
                <cac:Party>
                    <cac:PartyLegalEntity>
                        <cbc:RegistrationName>{{ $subcontractor->name ?? '' }}</cbc:RegistrationName>
                    </cac:PartyLegalEntity>
                </cac:Party>
            </cac:LogisticsOperatorParty>
        </cac:Consignment>
        @endif
        <cac:ShipmentStage>
            @if(!$isCarrier)
            <cbc:TransportModeCode listName="Modalidad de traslado" listAgencyName="PE:SUNAT" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo18">{{ $document->transport_mode_type_id }}</cbc:TransportModeCode>
            @endif
            <cac:TransitPeriod>
                <cbc:StartDate>{{ $document->date_of_shipping->format('Y-m-d') }}</cbc:StartDate>
            </cac:TransitPeriod>
            @if($isCarrier && !empty($company->mtc_registration_number))
            <cac:CarrierParty>
                <cac:PartyLegalEntity>
                    <cbc:CompanyID>{{ $company->mtc_registration_number }}</cbc:CompanyID>
                </cac:PartyLegalEntity>
            </cac:CarrierParty>
            @endif
            @if($isPublicTransport && $dispatcher)
            <cac:CarrierParty>
                <cac:PartyIdentification>
                    <cbc:ID schemeID="{{ $dispatcher->identity_document_type_id ?? '' }}">{{ $dispatcher->number ?? '' }}</cbc:ID>
                </cac:PartyIdentification>
                <cac:PartyLegalEntity>
                    <cbc:RegistrationName>{{ $dispatcher->name ?? '' }}</cbc:RegistrationName>
                    @if(!empty($dispatcher->mtc_number ?? null))
                    <cbc:CompanyID>{{ $dispatcher->mtc_number }}</cbc:CompanyID>
                    @endif
                </cac:PartyLegalEntity>
            </cac:CarrierParty>
            @endif
            @if(($isCarrier || !$isPublicTransport) && $driver)
            <cac:DriverPerson>
                <cbc:ID schemeID="{{ $driver->identity_document_type_id ?? '' }}" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{ $driver->number ?? '' }}</cbc:ID>
                <cbc:FirstName>{{ $driverFirstName }}</cbc:FirstName>
                <cbc:FamilyName>{{ $driverFamilyName }}</cbc:FamilyName>
                <cbc:JobTitle>Principal</cbc:JobTitle>
                <cac:IdentityDocumentReference>
                    <cbc:ID>{{ $driver->license ?? '' }}</cbc:ID>
                </cac:IdentityDocumentReference>
            </cac:DriverPerson>
            @endif
        </cac:ShipmentStage>
        <cac:Delivery>
            <cac:DeliveryAddress>
                <cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">{{ $document->delivery->location_id }}</cbc:ID>
                <cac:AddressLine>
                    <cbc:Line>{{ $document->delivery->address }}</cbc:Line>
                </cac:AddressLine>
            </cac:DeliveryAddress>
            <cac:Despatch>
                <cac:DespatchAddress>
                    <cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">{{ $document->origin->location_id }}</cbc:ID>
                    <cac:AddressLine>
                        <cbc:Line>{{ $document->origin->address }}</cbc:Line>
                    </cac:AddressLine>
                </cac:DespatchAddress>
                @if($isCarrier && $sender)
                <cac:DespatchParty>
                    <cac:PartyIdentification>
                        <cbc:ID schemeID="{{ $sender->identity_document_type_id ?? '' }}" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">{{ $sender->number ?? '' }}</cbc:ID>
                    </cac:PartyIdentification>
                    <cac:PartyLegalEntity>
                        <cbc:RegistrationName>{{ $sender->name ?? '' }}</cbc:RegistrationName>
                    </cac:PartyLegalEntity>
                </cac:DespatchParty>
                @endif
            </cac:Despatch>
        </cac:Delivery>
        @if(!$isCarrier && $document->container_number)
        <cac:TransportHandlingUnit>
            <cac:Package>
                <cbc:ID>1</cbc:ID>
                <cbc:TraceID>{{ $document->container_number }}</cbc:TraceID>
            </cac:Package>
        </cac:TransportHandlingUnit>
        @endif
        @if($isCarrier && $licensePlate !== '')
        <cac:TransportHandlingUnit>
            <cac:TransportEquipment>
                <cbc:ID>{{ $licensePlate }}</cbc:ID>
                @if($semitrailer !== '')
                <cac:AttachedTransportEquipment>
                    <cbc:ID>{{ $semitrailer }}</cbc:ID>
                    @if(!empty($semitrailerTuc))
                    <cac:ShipmentDocumentReference>
                        <cbc:ID>{{ $semitrailerTuc }}</cbc:ID>
                    </cac:ShipmentDocumentReference>
                    @endif
                </cac:AttachedTransportEquipment>
                @endif
                @if(!empty($vehicle->tuc ?? null))
                <cac:ShipmentDocumentReference>
                    <cbc:ID>{{ $vehicle->tuc }}</cbc:ID>
                </cac:ShipmentDocumentReference>
                @endif
                @if(!empty($vehicle->authorization_number ?? null))
                <cac:ShipmentDocumentReference>
                    <cbc:ID schemeID="{{ $vehicle->authorization_entity_id ?? '' }}" schemeName="Entidad Autorizadora" schemeAgencyName="PE:SUNAT">{{ $vehicle->authorization_number }}</cbc:ID>
                </cac:ShipmentDocumentReference>
                @endif
            </cac:TransportEquipment>
        </cac:TransportHandlingUnit>
        @endif
        @if(!$isCarrier && !$isPublicTransport && $licensePlate !== '')
        <cac:TransportHandlingUnit>
            <cac:TransportEquipment>
                <cbc:ID>{{ $licensePlate }}</cbc:ID>
                @if($semitrailer !== '')
                <cac:AttachedTransportEquipment>
                    <cbc:ID>{{ $semitrailer }}</cbc:ID>
                </cac:AttachedTransportEquipment>
                @endif
            </cac:TransportEquipment>
        </cac:TransportHandlingUnit>
        @endif
        @if(!$isCarrier && $document->port_code)
        <cac:FirstArrivalPortLocation>
            <cbc:ID schemeAgencyName="PE:SUNAT" schemeName="Puertos" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo63">{{ $document->port_code }}</cbc:ID>
            <cbc:LocationTypeCode>1</cbc:LocationTypeCode>
        </cac:FirstArrivalPortLocation>
        @endif
    </cac:Shipment>
    @foreach($document->items as $row)
    <cac:DespatchLine>
        <cbc:ID>{{ $loop->iteration }}</cbc:ID>
        <cbc:DeliveredQuantity unitCode="{{ $row->item->unit_type_id }}">{{ rtrim(rtrim(number_format((float) $row->quantity, 10, '.', ''), '0'), '.') }}</cbc:DeliveredQuantity>
        <cac:OrderLineReference>
            <cbc:LineID>{{ $loop->iteration }}</cbc:LineID>
        </cac:OrderLineReference>
        <cac:Item>
            <cbc:Description>{{ $row->item->description }}</cbc:Description>
            <cac:SellersItemIdentification>
                <cbc:ID>{{ $row->item->internal_id ?: $row->item_id }}</cbc:ID>
            </cac:SellersItemIdentification>
            @if(!empty($row->item->item_code ?? null))
            <cac:CommodityClassification>
                <cbc:ItemClassificationCode listID="UNSPSC" listAgencyName="GS1 US" listName="Item Classification">{{ $row->item->item_code }}</cbc:ItemClassificationCode>
            </cac:CommodityClassification>
            @endif
        </cac:Item>
    </cac:DespatchLine>
    @endforeach
</DespatchAdvice>
