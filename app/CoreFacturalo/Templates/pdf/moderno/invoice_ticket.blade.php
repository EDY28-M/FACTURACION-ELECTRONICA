@php
    use App\Support\PdfFormat as F;

    // Ticket de 80 mm por defecto; las variantes de 58 y 50 mm reutilizan esta plantilla con $paper.
    $paper = $paper ?? 78;
    $fs = $paper <= 45 ? '6.5pt' : ($paper <= 56 ? '7pt' : '8pt');
    $fs_name = $paper <= 56 ? '9pt' : '10.5pt';
    $fs_number = $paper <= 45 ? '9pt' : ($paper <= 56 ? '10pt' : '12pt');
    $fs_total = $paper <= 56 ? '8.5pt' : '10pt';
    $qr_size = $paper <= 45 ? '20mm' : ($paper <= 56 ? '22mm' : '26mm');
    $logo_w = ($paper - 30).'mm';
    $amount_w = $paper <= 56 ? '18mm' : '24mm';

    $establishment = $document->establishment;
    $customer = $document->customer;
    $invoice = $document->invoice;
    $document_number = $document->series.'-'.str_pad($document->number, 8, '0', STR_PAD_LEFT);
    $accounts = \App\Models\Tenant\BankAccount::where('show_in_documents', true)->get();
    $document_base = ($document->note) ? $document->note : null;
    $payments = $document->payments;
    $symbol = $document->currency_type->symbol;

    if ($document_base) {
        $affected_document_number = ($document_base->affected_document)
            ? $document_base->affected_document->series.'-'.str_pad($document_base->affected_document->number, 8, '0', STR_PAD_LEFT)
            : $document_base->data_affected_document->series.'-'.str_pad($document_base->data_affected_document->number, 8, '0', STR_PAD_LEFT);
    } else {
        $affected_document_number = null;
    }
    $document->load('reference_guides');

    $total_payment = $document->payments->sum('payment');
    $balance = ($document->total - $total_payment) - $document->payments->sum('change');

    $anonymous = F::isAnonymous($customer);
    $company_address = F::address($establishment);
    $customer_address = $anonymous ? '' : F::address($customer);
    $due = ($invoice && $invoice->date_of_due && $invoice->date_of_due->format('Y-m-d') !== $document->date_of_issue->format('Y-m-d'))
        ? F::date($invoice->date_of_due) : null;
    $payment_condition = F::paymentCondition($document);
    $fees = $document->fee ?? collect();

    // Estilos (mPDF no procesa <style> en este modo: van en línea).
    $line = 'border-top:0.2mm solid #000;';
    $dash = 'border-top:0.15mm solid #999;';
    $k = 'padding:0.6mm 0;color:#444;vertical-align:top;white-space:nowrap;';
    $v = 'padding:0.6mm 0 0.6mm 2mm;vertical-align:top;';
    $kp = 'padding:0.6mm 0;color:#444;vertical-align:top;';
    $vp = 'padding:0.6mm 0 0.6mm 1.5mm;vertical-align:top;text-align:right;white-space:nowrap;';
@endphp
<html>
<head></head>
<body>
@if($document->state_type->id == '11')
    <div style="position:absolute;top:70mm;left:2mm;width:{{ $paper - 4 }}mm;text-align:center;">
        <img src="data:{{mime_content_type(public_path("status_images".DIRECTORY_SEPARATOR."anulado.png"))}};base64, {{base64_encode(file_get_contents(public_path("status_images".DIRECTORY_SEPARATOR."anulado.png")))}}" alt="anulado" style="opacity:0.6;">
    </div>
@endif
<div style="font-family:dejavusanscondensed;font-size:{{ $fs }};color:#000;">

@if($company->logo)
    <div style="text-align:center;padding-top:3mm;">
        <img src="data:{{mime_content_type(public_path("storage/uploads/logos/{$company->logo}"))}};base64, {{base64_encode(file_get_contents(public_path("storage/uploads/logos/{$company->logo}")))}}" alt="{{$company->name}}" style="max-width:{{ $logo_w }};max-height:20mm;">
    </div>
@endif


{{-- Emisor --}}
<table width="100%" style="margin-top:2mm;">
    <tr><td style="text-align:center;font-size:{{ $fs_name }};font-weight:bold;">{{ $company->name }}</td></tr>
    @if(F::filled($company->trade_name) && mb_strtolower(trim($company->trade_name)) !== mb_strtolower(trim($company->name)))
        <tr><td style="text-align:center;">{{ $company->trade_name }}</td></tr>
    @endif
    <tr><td style="text-align:center;font-weight:bold;">RUC {{ $company->number }}</td></tr>
    @if($company_address)
        <tr><td style="text-align:center;font-size:7.5pt;color:#333;">{{ $company_address }}</td></tr>
    @endif
    @if(F::filled($establishment->trade_address ?? null))
        <tr><td style="text-align:center;font-size:7.5pt;color:#333;">{{ $establishment->trade_address }}</td></tr>
    @endif
    @if(F::filled($establishment->telephone))
        <tr><td style="text-align:center;font-size:7.5pt;color:#333;">Tel. {{ $establishment->telephone }}</td></tr>
    @endif
    @if(F::filled($establishment->web_address ?? null))
        <tr><td style="text-align:center;font-size:7.5pt;color:#333;">{{ $establishment->web_address }}</td></tr>
    @endif
    @if(F::filled($establishment->aditional_information ?? null))
        <tr><td style="text-align:center;font-size:7.5pt;color:#333;">{{ $establishment->aditional_information }}</td></tr>
    @endif
</table>

{{-- Comprobante --}}
<table width="100%" style="margin-top:2.5mm;">
    <tr><td style="{{ $line }}padding-top:2mm;text-align:center;font-size:8.5pt;font-weight:bold;">{{ mb_strtoupper($document->document_type->description) }}</td></tr>
    <tr><td style="padding-bottom:2mm;text-align:center;font-size:{{ $fs_number }};font-weight:bold;">{{ $document_number }}</td></tr>
</table>

{{-- Datos --}}
<table width="100%" style="{{ $line }}padding-top:1mm;">
    <tr><td style="{{ $k }}padding-top:1.5mm;">Fecha</td><td style="{{ $v }}padding-top:1.5mm;">{{ F::date($document->date_of_issue) }} {{ F::time($document->time_of_issue) }}</td></tr>
    @if($due)
        <tr><td style="{{ $k }}">Vence</td><td style="{{ $v }}">{{ $due }}</td></tr>
    @endif
    <tr><td style="{{ $k }}">Cliente</td><td style="{{ $v }}">{{ $customer->name }}</td></tr>
    @unless($anonymous)
        <tr><td style="{{ $k }}">{{ F::identityLabel($customer) }}</td><td style="{{ $v }}">{{ $customer->number }}</td></tr>
    @endunless
    @if($customer_address)
        <tr><td style="{{ $k }}">Dirección</td><td style="{{ $v }}">{{ $customer_address }}</td></tr>
    @endif
    @if ($document->reference_data)
        <tr><td style="{{ $k }}">Referencia</td><td style="{{ $v }}">{{ $document->reference_data }}</td></tr>
    @endif
    @if ($document->purchase_order)
        <tr><td style="{{ $k }}">Orden de compra</td><td style="{{ $v }}">{{ $document->purchase_order }}</td></tr>
    @endif
    @if ($document->quotation_id)
        <tr><td style="{{ $k }}">Cotización</td><td style="{{ $v }}">{{ $document->quotation->identifier }}</td></tr>
    @endif
    @isset($document->quotation->delivery_date)
        <tr><td style="{{ $k }}">F. entrega</td><td style="{{ $v }}">{{ $document->date_of_issue->addDays($document->quotation->delivery_date)->format('d/m/Y') }}</td></tr>
    @endisset
    @isset($document->quotation->sale_opportunity)
        <tr><td style="{{ $k }}">O. venta</td><td style="{{ $v }}">{{ $document->quotation->sale_opportunity->number_full }}</td></tr>
    @endisset
    @if ($document->prepayments)
        @foreach($document->prepayments as $p)
            <tr><td style="{{ $k }}">Anticipo</td><td style="{{ $v }}">{{ $p->number }}</td></tr>
        @endforeach
    @endif
    @if ($document->guides)
        @foreach($document->guides as $guide)
            <tr><td style="{{ $k }}">{{ $guide->document_type_description ?? $guide->document_type_id }}</td><td style="{{ $v }}">{{ $guide->number }}</td></tr>
        @endforeach
    @endif
    @if (count($document->reference_guides) > 0)
        @foreach($document->reference_guides as $guide)
            <tr><td style="{{ $k }}">Guía de remisión</td><td style="{{ $v }}">{{ $guide->series }}-{{ $guide->number }}</td></tr>
        @endforeach
    @endif
    @if(!is_null($document_base))
        <tr><td style="{{ $k }}">Doc. afectado</td><td style="{{ $v }}">{{ $affected_document_number }}</td></tr>
        <tr><td style="{{ $k }}">Tipo de nota</td><td style="{{ $v }}">{{ ($document_base->note_type === 'credit') ? $document_base->note_credit_type->description : $document_base->note_debit_type->description }}</td></tr>
        <tr><td style="{{ $k }}">Motivo</td><td style="{{ $v }}">{{ $document_base->note_description }}</td></tr>
    @endif
</table>

{{-- Detracción --}}
@if ($document->detraction)
    @inject('detractionType', 'App\Services\DetractionTypeService')
    <table width="100%" style="{{ $dash }}margin-top:1.5mm;">
        <tr><td colspan="2" style="padding-top:1.5mm;font-weight:bold;">Operación sujeta a detracción</td></tr>
        <tr><td style="{{ $k }}">Cta. detracciones</td><td style="{{ $v }}">{{ $document->detraction->bank_account }}</td></tr>
        <tr><td style="{{ $k }}">Bien/servicio</td><td style="{{ $v }}">{{ $document->detraction->detraction_type_id }} - {{ $detractionType->getDetractionTypeDescription($document->detraction->detraction_type_id) }}</td></tr>
        <tr><td style="{{ $k }}">Medio de pago</td><td style="{{ $v }}">{{ $detractionType->getPaymentMethodTypeDescription($document->detraction->payment_method_id) }}</td></tr>
        <tr><td style="{{ $k }}">Porcentaje</td><td style="{{ $v }}">{{ $document->detraction->percentage }}%</td></tr>
        <tr><td style="{{ $k }}">Monto</td><td style="{{ $v }}">S/ {{ F::money($document->detraction->amount) }}</td></tr>
        @if($document->detraction->pay_constancy)
            <tr><td style="{{ $k }}">Constancia</td><td style="{{ $v }}">{{ $document->detraction->pay_constancy }}</td></tr>
        @endif
        @if($invoice && $invoice->operation_type_id == '1004')
            <tr><td colspan="2" style="padding-top:1.5mm;font-weight:bold;">Servicio de transporte de carga</td></tr>
            <tr><td style="{{ $k }}">Ubigeo origen</td><td style="{{ $v }}">{{ $document->detraction->origin_location_id[2] }}</td></tr>
            <tr><td style="{{ $k }}">Dir. origen</td><td style="{{ $v }}">{{ $document->detraction->origin_address }}</td></tr>
            <tr><td style="{{ $k }}">Ubigeo destino</td><td style="{{ $v }}">{{ $document->detraction->delivery_location_id[2] }}</td></tr>
            <tr><td style="{{ $k }}">Dir. destino</td><td style="{{ $v }}">{{ $document->detraction->delivery_address }}</td></tr>
            <tr><td style="{{ $k }}">V. ref. servicio</td><td style="{{ $v }}">{{ $document->detraction->reference_value_service }}</td></tr>
            <tr><td style="{{ $k }}">V. ref. carga efectiva</td><td style="{{ $v }}">{{ $document->detraction->reference_value_effective_load }}</td></tr>
            <tr><td style="{{ $k }}">V. ref. carga útil</td><td style="{{ $v }}">{{ $document->detraction->reference_value_payload }}</td></tr>
            <tr><td style="{{ $k }}">Detalle del viaje</td><td style="{{ $v }}">{{ $document->detraction->trip_detail }}</td></tr>
        @endif
    </table>
@endif

{{-- Detalle --}}
<table width="100%" style="margin-top:2mm;">
    <tr>
        <td style="{{ $line }}border-bottom:0.2mm solid #000;padding:1mm 0;font-size:7.5pt;font-weight:bold;">DESCRIPCIÓN</td>
        <td style="{{ $line }}border-bottom:0.2mm solid #000;padding:1mm 0;font-size:7.5pt;font-weight:bold;text-align:right;">IMPORTE</td>
    </tr>
    @foreach($document->items as $row)
        <tr>
            <td colspan="2" style="padding-top:1.5mm;font-weight:bold;">
                @if($row->name_product_pdf)
                    {!! $row->name_product_pdf !!}
                @else
                    {!! $row->item->description !!}
                @endif
                @if (!empty($row->item->presentation)) {!! $row->item->presentation->description !!} @endif
            </td>
        </tr>
        @php
            $extra = [];
            foreach ($row->additional_information ?? [] as $information) {
                if ($information) { $extra[] = e($information); }
            }
            if ($row->attributes) {
                foreach ($row->attributes as $attr) { $extra[] = $attr->description.': '.e($attr->value); }
            }
            if ($row->discounts) {
                foreach ($row->discounts as $dtos) { $extra[] = e(($dtos->factor * 100).'% '.$dtos->description); }
            }
        @endphp
        @if(count($extra) || $row->item->is_set == 1 || $document->has_prepayment)
            <tr>
                <td colspan="2" style="font-size:7pt;color:#444;">
                    {!! implode('<br>', $extra) !!}
                    @if($row->item->is_set == 1)
                        @inject('itemSet', 'App\Services\ItemSetService')
                        @foreach ($itemSet->getItemsSet($row->item_id) as $item)
                            <br>{{ $item }}
                        @endforeach
                    @endif
                    @if($document->has_prepayment)
                        <br>*** Pago anticipado ***
                    @endif
                </td>
            </tr>
        @endif
        <tr>
            <td style="padding-bottom:1.5mm;color:#333;border-bottom:0.2mm dotted #999;">{{ F::quantity($row->quantity) }} {{ F::unit($row->item->unit_type_id) }} x {{ F::money($row->unit_price) }}</td>
            <td style="padding-bottom:1.5mm;text-align:right;font-weight:bold;border-bottom:0.2mm dotted #999;">{{ F::money($row->total) }}</td>
        </tr>
    @endforeach
    @if ($document->prepayments)
        @foreach($document->prepayments as $p)
            <tr><td colspan="2" style="padding-top:1.5mm;font-weight:bold;">ANTICIPO: {{ ($p->document_type_id == '02') ? 'FACTURA' : 'BOLETA' }} NRO. {{ $p->number }}</td></tr>
            <tr>
                <td style="padding-bottom:1.5mm;color:#333;border-bottom:0.2mm dotted #999;">1 UND x -{{ F::money($p->total) }}</td>
                <td style="padding-bottom:1.5mm;text-align:right;font-weight:bold;border-bottom:0.2mm dotted #999;">-{{ F::money($p->total) }}</td>
            </tr>
        @endforeach
    @endif
</table>

{{-- Totales --}}
@php
    $totals = [];
    if ($document->total_exportation > 0) $totals['Op. exportación'] = $document->total_exportation;
    if ($document->total_free > 0) $totals['Op. gratuitas'] = $document->total_free;
    if ($document->total_unaffected > 0) $totals['Op. inafectas'] = $document->total_unaffected;
    if ($document->total_exonerated > 0) $totals['Op. exoneradas'] = $document->total_exonerated;
    if ($document->total_taxed > 0) $totals['Op. gravadas'] = $document->total_taxed;
    if ($document->total_discount > 0) $totals['Descuento total'] = $document->total_discount;
    if ($document->total_plastic_bag_taxes > 0) $totals['ICBPER'] = $document->total_plastic_bag_taxes;
    $totals['IGV'] = $document->total_igv;
@endphp
<table width="100%" style="margin-top:1.5mm;">
    @foreach($totals as $label => $amount)
        <tr>
            <td style="text-align:right;padding:0.4mm 0;color:#333;">{{ $label }}</td>
            <td style="text-align:right;padding:0.4mm 0;width:{{ $amount_w }};">{{ $symbol }} {{ F::money($amount) }}</td>
        </tr>
    @endforeach
    <tr>
        <td style="text-align:right;padding:1.2mm 0;font-size:{{ $fs_total }};font-weight:bold;{{ $line }}">TOTAL</td>
        <td style="text-align:right;padding:1.2mm 0;font-size:{{ $fs_total }};font-weight:bold;{{ $line }}">{{ $symbol }} {{ F::money($document->total) }}</td>
    </tr>
</table>

{{-- Leyendas --}}
<table width="100%" style="margin-top:1mm;">
    @foreach(array_reverse((array) $document->legends) as $row)
        <tr><td style="padding:0.6mm 0;">
            @if ($row->code == "1000")
                Son: <b>{{ F::amountInWords($row->value, $document->currency_type->description) }}</b>
            @else
                {{ $row->value }}
            @endif
        </td></tr>
    @endforeach
    @if ($document->detraction)
        <tr><td style="padding:0.6mm 0;font-weight:bold;">Operación sujeta al Sistema de Pago de Obligaciones Tributarias</td></tr>
    @endif
</table>

{{-- Pago --}}
@if($payment_condition || $document->payment_method_type_id || $payments->count() || $balance < -0.004)
    <table width="100%" style="{{ $dash }}margin-top:1.5mm;">
        @if($payment_condition)
            <tr><td style="{{ $kp }}padding-top:1.5mm;">Forma de pago</td><td style="{{ $v }}padding-top:1.5mm;text-align:right;font-weight:bold;">{{ $payment_condition }}</td></tr>
        @endif
        @if($payment_condition === 'Crédito' && $fees->count())
            <tr><td style="{{ $kp }}">Pendiente de pago</td><td style="{{ $vp }}">{{ $symbol }} {{ F::money($fees->sum('amount')) }}</td></tr>
            @foreach($fees as $fee)
                <tr><td style="{{ $kp }}">Cuota {{ $loop->iteration }} · {{ F::date($fee->date) }}</td><td style="{{ $vp }}">{{ $symbol }} {{ F::money($fee->amount) }}</td></tr>
            @endforeach
        @endif
        @if($document->payment_method_type_id)
            <tr><td style="{{ $kp }}">Medio de pago</td><td style="{{ $vp }}">{{ $document->payment_method_type->description }}</td></tr>
        @endif
        @foreach($payments as $row)
            <tr><td style="{{ $kp }}">{{ $row->payment_method_type->description }}{{ $row->reference ? ' · '.$row->reference : '' }}</td><td style="{{ $vp }}">{{ $symbol }} {{ F::money($row->payment + $row->change) }}</td></tr>
        @endforeach
        @if($balance < -0.004)
            <tr><td style="{{ $kp }}">Vuelto</td><td style="{{ $vp }}">{{ $symbol }} {{ F::money(abs($balance)) }}</td></tr>
        @endif
    </table>
@endif

{{-- Información adicional y cuentas --}}
@php $infos = array_values(array_filter((array) $document->additional_information)); @endphp
@if(count($infos))
    <table width="100%" style="{{ $dash }}margin-top:1.5mm;">
        <tr><td style="padding-top:1.5mm;font-weight:bold;">Información adicional</td></tr>
        @foreach($infos as $information)
            <tr><td style="font-size:7.5pt;">{{ $information }}</td></tr>
        @endforeach
    </table>
@endif

@if(in_array($document->document_type->id, ['01', '03']) && $accounts->count())
    <table width="100%" style="{{ $dash }}margin-top:1.5mm;font-size:7pt;">
        <tr><td style="padding-top:1.5mm;font-weight:bold;font-size:7.5pt;">Cuentas para depósito</td></tr>
        @foreach($accounts as $account)
            <tr><td style="padding-top:0.8mm;">
                <b>{{ $account->bank->description }}</b> · {{ $account->currency_type->description }}<br>
                N° {{ $account->number }}@if($account->cci) · CCI {{ $account->cci }}@endif
            </td></tr>
        @endforeach
    </table>
@endif

{{-- Validación --}}
<table width="100%" style="{{ $line }}margin-top:2mm;">
    @if($document->qr)
        <tr><td style="text-align:center;padding-top:2mm;"><img src="data:image/png;base64, {{ $document->qr }}" style="width:{{ $qr_size }};height:{{ $qr_size }};" /></td></tr>
    @endif
    <tr><td style="text-align:center;font-size:6.5pt;color:#444;">Hash: {{ $document->hash }}</td></tr>
    <tr><td style="text-align:center;font-size:7pt;padding-top:1.5mm;">
        Representación impresa de la {{ mb_strtolower($document->document_type->description) }}.<br>
        Consúltela en {!! url('/buscar') !!}
    </td></tr>
    @if ($customer->department_id == 16)
        <tr><td style="text-align:center;font-size:7pt;padding-top:1mm;font-weight:bold;">Bienes transferidos en la Amazonía para ser consumidos en la misma</td></tr>
    @endif
    <tr><td style="text-align:center;font-size:7pt;color:#444;padding-top:1.5mm;">
        Atendido por: {{ $document->seller ? $document->seller->name : $document->user->name }}
    </td></tr>
</table>

@if ($document->terms_condition)
    <table width="100%" style="{{ $dash }}margin-top:2mm;font-size:7pt;">
        <tr><td style="padding-top:1.5mm;font-weight:bold;">Términos y condiciones</td></tr>
        <tr><td>{!! $document->terms_condition !!}</td></tr>
    </table>
@endif

</div>
</body>
</html>
