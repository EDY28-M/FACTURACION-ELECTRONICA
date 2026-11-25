@php
    use App\Support\PdfFormat as F;

    // A5 reutiliza esta plantilla en modo compacto.
    $compact = $compact ?? false;
    $fs = $compact ? '7.5pt' : '8.5pt';
    $gap = $compact ? '3mm' : '6mm';
    $qr_size = $compact ? '22mm' : '30mm';

    $establishment = $document->establishment;
    $customer = $document->customer;
    $invoice = $document->invoice;
    $document_base = ($document->note) ? $document->note : null;
    $document_number = $document->series.'-'.str_pad($document->number, 8, '0', STR_PAD_LEFT);
    $accounts = \App\Models\Tenant\BankAccount::where('show_in_documents', true)->get();
    $symbol = $document->currency_type->symbol;

    if ($document_base) {
        $affected_document_number = ($document_base->affected_document)
            ? $document_base->affected_document->series.'-'.str_pad($document_base->affected_document->number, 8, '0', STR_PAD_LEFT)
            : $document_base->data_affected_document->series.'-'.str_pad($document_base->data_affected_document->number, 8, '0', STR_PAD_LEFT);
    } else {
        $affected_document_number = null;
    }

    $payments = $document->payments;
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

    // Columnas opcionales: solo se muestran si algún ítem tiene el dato.
    $lotService = app(\App\Services\ItemLotsGroupService::class);
    $rows = [];
    $has_model = $has_lot = $has_series = $has_discount = false;
    foreach ($document->items as $row) {
        $lot = trim((string) $lotService->getLote($row->item->IdLoteSelected ?? null));
        $series = [];
        foreach (($row->item->lots ?? []) as $l) {
            if (isset($l->has_sale) && $l->has_sale) { $series[] = $l->series; }
        }
        $discount = 0;
        foreach (($row->discounts ?? []) as $d) { $discount += $d->amount; }
        $model = trim((string) ($row->item->model ?? ''));
        $has_model = $has_model || $model !== '';
        $has_lot = $has_lot || $lot !== '';
        $has_series = $has_series || count($series) > 0;
        $has_discount = $has_discount || $discount > 0;
        $rows[] = compact('row', 'lot', 'series', 'discount', 'model');
    }
    $cols = 5 + ($has_model ? 1 : 0) + ($has_lot ? 1 : 0) + ($has_series ? 1 : 0) + ($has_discount ? 1 : 0);

    // Estilos en línea (mPDF no procesa <style> en este modo).
    $muted = 'color:#555;';
    $th = 'padding:'.($compact ? '1.2mm' : '2mm').' 1.5mm;background:#f2f2f2;border-bottom:0.3mm solid #000;font-size:7.5pt;font-weight:bold;';
    $td = 'padding:'.($compact ? '0.9mm' : '1.8mm').' 1.5mm;border-bottom:0.15mm solid #ccc;vertical-align:top;';
    $k = 'padding:'.($compact ? '0.4mm' : '0.8mm').' 0;color:#555;vertical-align:top;white-space:nowrap;width:30mm;';
    $v = 'padding:'.($compact ? '0.4mm' : '0.8mm').' 0;vertical-align:top;';
@endphp
<html>
<head></head>
<body>
@if($document->state_type->id == '11')
    <div style="position:absolute;top:95mm;left:15mm;width:180mm;text-align:center;">
        <img src="data:{{mime_content_type(public_path("status_images".DIRECTORY_SEPARATOR."anulado.png"))}};base64, {{base64_encode(file_get_contents(public_path("status_images".DIRECTORY_SEPARATOR."anulado.png")))}}" alt="anulado" style="opacity:0.6;">
    </div>
@endif
<div style="font-family:dejavusans;font-size:{{ $fs }};color:#000;">


{{-- Encabezado --}}
<table width="100%">
    <tr>
        @if($company->logo)
            <td style="width:30mm;vertical-align:middle;padding-right:4mm;">
                <img src="data:{{mime_content_type(public_path("storage/uploads/logos/{$company->logo}"))}};base64, {{base64_encode(file_get_contents(public_path("storage/uploads/logos/{$company->logo}")))}}" alt="{{$company->name}}" style="max-width:30mm;max-height:24mm;">
            </td>
        @endif
        <td style="vertical-align:middle;">
            <div style="font-size:13pt;font-weight:bold;">{{ $company->name }}</div>
            @if(F::filled($company->trade_name) && mb_strtolower(trim($company->trade_name)) !== mb_strtolower(trim($company->name)))
                <div style="font-size:9pt;">{{ $company->trade_name }}</div>
            @endif
            <div style="font-size:8pt;{{ $muted }}margin-top:1mm;">
                @if($company_address){{ $company_address }}<br>@endif
                @if(F::filled($establishment->trade_address ?? null)){{ $establishment->trade_address }}<br>@endif
                @if(F::filled($establishment->telephone))Tel. {{ $establishment->telephone }}<br>@endif
                @if(F::filled($establishment->web_address ?? null)){{ $establishment->web_address }}<br>@endif
                @if(F::filled($establishment->aditional_information ?? null)){{ $establishment->aditional_information }}@endif
            </div>
        </td>
        <td style="width:62mm;vertical-align:middle;">
            <table width="100%" style="border:0.35mm solid #000;">
                <tr><td style="text-align:center;padding:2.5mm 2mm 0.5mm;font-size:10pt;font-weight:bold;">RUC {{ $company->number }}</td></tr>
                <tr><td style="text-align:center;padding:0.5mm 2mm;font-size:9.5pt;font-weight:bold;">{{ mb_strtoupper($document->document_type->description) }}</td></tr>
                <tr><td style="text-align:center;padding:0.5mm 2mm 2.5mm;font-size:12pt;font-weight:bold;">{{ $document_number }}</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Cliente y datos del comprobante --}}
<table width="100%" style="margin-top:{{ $gap }};border-top:0.3mm solid #000;border-bottom:0.15mm solid #ccc;">
    <tr>
        <td style="width:58%;vertical-align:top;padding:2mm 4mm 2mm 0;">
            <table width="100%">
                <tr><td style="{{ $k }}">Cliente</td><td style="{{ $v }}font-weight:bold;">{{ $customer->name }}</td></tr>
                @unless($anonymous)
                    <tr><td style="{{ $k }}">{{ F::identityLabel($customer) }}</td><td style="{{ $v }}">{{ $customer->number }}</td></tr>
                @endunless
                @if($customer_address)
                    <tr><td style="{{ $k }}">Dirección</td><td style="{{ $v }}">{{ $customer_address }}</td></tr>
                @endif
                @if (!$anonymous && ($customer->internal_code ?? false))
                    <tr><td style="{{ $k }}">Código</td><td style="{{ $v }}">{{ $customer->internal_code }}</td></tr>
                @endif
            </table>
        </td>
        <td style="width:42%;vertical-align:top;padding:2mm 0;">
            <table width="100%">
                <tr><td style="{{ $k }}">Fecha de emisión</td><td style="{{ $v }}">{{ F::date($document->date_of_issue) }} {{ F::time($document->time_of_issue) }}</td></tr>
                @if($due)
                    <tr><td style="{{ $k }}">Vencimiento</td><td style="{{ $v }}">{{ $due }}</td></tr>
                @endif
                <tr><td style="{{ $k }}">Moneda</td><td style="{{ $v }}">{{ $document->currency_type->description }}</td></tr>
                @if($payment_condition)
                    <tr><td style="{{ $k }}">Forma de pago</td><td style="{{ $v }}font-weight:bold;">{{ $payment_condition }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

{{-- Referencias --}}
@php
    $refs = [];
    if ($document->reference_data) $refs[] = ['Referencia', $document->reference_data];
    if ($document->purchase_order) $refs[] = ['Orden de compra', $document->purchase_order];
    if ($document->quotation_id) $refs[] = ['Cotización', $document->quotation->identifier];
    if (isset($document->quotation->delivery_date)) $refs[] = ['F. entrega', $document->date_of_issue->addDays($document->quotation->delivery_date)->format('d/m/Y')];
    if (isset($document->quotation->sale_opportunity)) $refs[] = ['O. venta', $document->quotation->sale_opportunity->number_full];
    foreach (($document->prepayments ?? []) as $p) $refs[] = ['Anticipo', $p->number];
    foreach (($document->guides ?? []) as $guide) $refs[] = [$guide->document_type_description ?? $guide->document_type_id, $guide->number];
    foreach ($document->reference_guides as $guide) $refs[] = ['Guía de remisión', $guide->series.'-'.$guide->number];
    if (!is_null($document_base)) {
        $refs[] = ['Doc. afectado', $affected_document_number];
        $refs[] = ['Tipo de nota', ($document_base->note_type === 'credit') ? $document_base->note_credit_type->description : $document_base->note_debit_type->description];
        $refs[] = ['Motivo', $document_base->note_description];
    }
@endphp
@if(count($refs))
    <table width="100%" style="margin-top:1mm;border-bottom:0.15mm solid #ccc;">
        @foreach(array_chunk($refs, 2) as $pair)
            <tr>
                @foreach($pair as $ref)
                    <td style="{{ $k }}">{{ $ref[0] }}</td><td style="{{ $v }}width:60mm;">{{ $ref[1] }}</td>
                @endforeach
                @if(count($pair) === 1)<td></td><td></td>@endif
            </tr>
        @endforeach
    </table>
@endif

{{-- Detracción --}}
@if ($document->detraction)
    @inject('detractionType', 'App\Services\DetractionTypeService')
    <table width="100%" style="margin-top:2mm;border:0.15mm solid #ccc;">
        <tr><td colspan="4" style="padding:1.5mm 2mm 0.5mm;font-weight:bold;">Operación sujeta a detracción</td></tr>
        <tr>
            <td style="{{ $k }}padding-left:2mm;">Cta. detracciones</td><td style="{{ $v }}">{{ $document->detraction->bank_account }}</td>
            <td style="{{ $k }}">Bien/servicio</td><td style="{{ $v }}">{{ $document->detraction->detraction_type_id }} - {{ $detractionType->getDetractionTypeDescription($document->detraction->detraction_type_id) }}</td>
        </tr>
        <tr>
            <td style="{{ $k }}padding-left:2mm;">Medio de pago</td><td style="{{ $v }}">{{ $detractionType->getPaymentMethodTypeDescription($document->detraction->payment_method_id) }}</td>
            <td style="{{ $k }}">Porcentaje / monto</td><td style="{{ $v }}">{{ $document->detraction->percentage }}% · S/ {{ F::money($document->detraction->amount) }}</td>
        </tr>
        @if($document->detraction->pay_constancy)
            <tr><td style="{{ $k }}padding-left:2mm;">Constancia de pago</td><td colspan="3" style="{{ $v }}">{{ $document->detraction->pay_constancy }}</td></tr>
        @endif
        @if($invoice && $invoice->operation_type_id == '1004')
            <tr><td colspan="4" style="padding:1.5mm 2mm 0.5mm;font-weight:bold;">Servicio de transporte de carga</td></tr>
            <tr>
                <td style="{{ $k }}padding-left:2mm;">Ubigeo origen</td><td style="{{ $v }}">{{ $document->detraction->origin_location_id[2] }}</td>
                <td style="{{ $k }}">Dirección origen</td><td style="{{ $v }}">{{ $document->detraction->origin_address }}</td>
            </tr>
            <tr>
                <td style="{{ $k }}padding-left:2mm;">Ubigeo destino</td><td style="{{ $v }}">{{ $document->detraction->delivery_location_id[2] }}</td>
                <td style="{{ $k }}">Dirección destino</td><td style="{{ $v }}">{{ $document->detraction->delivery_address }}</td>
            </tr>
            <tr>
                <td style="{{ $k }}padding-left:2mm;">V. ref. servicio</td><td style="{{ $v }}">{{ $document->detraction->reference_value_service }}</td>
                <td style="{{ $k }}">V. ref. carga efectiva</td><td style="{{ $v }}">{{ $document->detraction->reference_value_effective_load }}</td>
            </tr>
            <tr>
                <td style="{{ $k }}padding-left:2mm;">V. ref. carga útil</td><td style="{{ $v }}">{{ $document->detraction->reference_value_payload }}</td>
                <td style="{{ $k }}">Detalle del viaje</td><td style="{{ $v }}">{{ $document->detraction->trip_detail }}</td>
            </tr>
        @endif
    </table>
@endif

{{-- Detalle --}}
<table width="100%" style="margin-top:{{ $gap }};">
    <thead>
    <tr>
        <th style="{{ $th }}text-align:center;width:7%;">CANT.</th>
        <th style="{{ $th }}text-align:center;width:7%;">UND.</th>
        <th style="{{ $th }}text-align:left;">DESCRIPCIÓN</th>
        @if($has_model)<th style="{{ $th }}text-align:left;width:11%;">MODELO</th>@endif
        @if($has_lot)<th style="{{ $th }}text-align:center;width:9%;">LOTE</th>@endif
        @if($has_series)<th style="{{ $th }}text-align:center;width:11%;">SERIE</th>@endif
        <th style="{{ $th }}text-align:right;width:12%;">P. UNIT.</th>
        @if($has_discount)<th style="{{ $th }}text-align:right;width:9%;">DSCTO.</th>@endif
        <th style="{{ $th }}text-align:right;width:13%;">IMPORTE</th>
    </tr>
    </thead>
    <tbody>
    @foreach($rows as $r)
        @php $row = $r['row']; @endphp
        <tr>
            <td style="{{ $td }}text-align:center;">{{ F::quantity($row->quantity) }}</td>
            <td style="{{ $td }}text-align:center;">{{ F::unit($row->item->unit_type_id) }}</td>
            <td style="{{ $td }}">
                @if($row->name_product_pdf)
                    {!! $row->name_product_pdf !!}
                @else
                    {!! $row->item->description !!}
                @endif
                @if (!empty($row->item->presentation)) {!! $row->item->presentation->description !!} @endif
                @if($row->attributes)
                    @foreach($row->attributes as $attr)
                        <br><span style="font-size:7pt;{{ $muted }}">{!! $attr->description !!}: {{ $attr->value }}</span>
                    @endforeach
                @endif
                @if($row->discounts)
                    @foreach($row->discounts as $dtos)
                        <br><span style="font-size:7pt;{{ $muted }}">{{ $dtos->factor * 100 }}% {{ $dtos->description }}</span>
                    @endforeach
                @endif
                @if($row->item->is_set == 1)
                    @inject('itemSet', 'App\Services\ItemSetService')
                    @foreach ($itemSet->getItemsSet($row->item_id) as $item)
                        <br><span style="font-size:7pt;{{ $muted }}">{{ $item }}</span>
                    @endforeach
                @endif
                @if($document->has_prepayment)
                    <br><span style="font-size:7pt;">*** Pago anticipado ***</span>
                @endif
            </td>
            @if($has_model)<td style="{{ $td }}">{{ $r['model'] }}</td>@endif
            @if($has_lot)<td style="{{ $td }}text-align:center;">{{ $r['lot'] }}</td>@endif
            @if($has_series)<td style="{{ $td }}text-align:center;font-size:7pt;">{!! implode('<br>', array_map('e', $r['series'])) !!}</td>@endif
            <td style="{{ $td }}text-align:right;">{{ F::money($row->unit_price) }}</td>
            @if($has_discount)<td style="{{ $td }}text-align:right;">{{ $r['discount'] > 0 ? F::money($r['discount']) : '' }}</td>@endif
            <td style="{{ $td }}text-align:right;">{{ F::money($row->total) }}</td>
        </tr>
    @endforeach
    @if ($document->prepayments)
        @foreach($document->prepayments as $p)
            <tr>
                <td style="{{ $td }}text-align:center;">1</td>
                <td style="{{ $td }}text-align:center;">UND</td>
                <td style="{{ $td }}">ANTICIPO: {{ ($p->document_type_id == '02') ? 'FACTURA' : 'BOLETA' }} NRO. {{ $p->number }}</td>
                @if($has_model)<td style="{{ $td }}"></td>@endif
                @if($has_lot)<td style="{{ $td }}"></td>@endif
                @if($has_series)<td style="{{ $td }}"></td>@endif
                <td style="{{ $td }}text-align:right;">-{{ F::money($p->total) }}</td>
                @if($has_discount)<td style="{{ $td }}"></td>@endif
                <td style="{{ $td }}text-align:right;">-{{ F::money($p->total) }}</td>
            </tr>
        @endforeach
    @endif
    </tbody>
</table>

{{-- Totales --}}
@php
    $totals = [];
    if ($document->total_exportation > 0) $totals['Op. exportación'] = $document->total_exportation;
    if ($document->total_free > 0) $totals['Op. gratuitas'] = $document->total_free;
    if ($document->total_unaffected > 0) $totals['Op. inafectas'] = $document->total_unaffected;
    if ($document->total_exonerated > 0) $totals['Op. exoneradas'] = $document->total_exonerated;
    if ($document->total_taxed > 0) $totals['Op. gravadas'] = $document->total_taxed;
    if ($document->total_discount > 0) $totals[($document->total_prepayment > 0) ? 'Anticipo' : 'Descuento total'] = $document->total_discount;
    if ($document->total_plastic_bag_taxes > 0) $totals['ICBPER'] = $document->total_plastic_bag_taxes;
    $totals['IGV'] = $document->total_igv;
    if ($document->perception) {
        $totals['Importe total'] = $document->total;
        $totals['Percepción'] = $document->perception->amount;
        $grand = $document->total + $document->perception->amount;
    } else {
        $grand = $document->total;
    }
@endphp
<table width="100%" style="margin-top:2mm;">
    <tr>
        <td style="width:55%;"></td>
        <td style="width:45%;">
            <table width="100%">
                @foreach($totals as $label => $amount)
                    <tr>
                        <td style="padding:0.8mm 0;{{ $muted }}">{{ $label }}</td>
                        <td style="padding:0.8mm 0;text-align:right;">{{ $symbol }} {{ F::money($amount) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td style="padding:2mm 0;border-top:0.3mm solid #000;font-size:11pt;font-weight:bold;">TOTAL</td>
                    <td style="padding:2mm 0;border-top:0.3mm solid #000;font-size:11pt;font-weight:bold;text-align:right;">{{ $symbol }} {{ F::money($grand) }}</td>
                </tr>
                @if($balance < -0.004)
                    <tr>
                        <td style="padding:0.8mm 0;{{ $muted }}">Vuelto</td>
                        <td style="padding:0.8mm 0;text-align:right;">{{ $symbol }} {{ F::money(abs($balance)) }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

{{-- Pie: importe en letras, pago, cuentas y validación --}}
@php $infos = array_values(array_filter((array) $document->additional_information)); @endphp
<table width="100%" style="margin-top:4mm;border-top:0.15mm solid #ccc;">
    <tr>
        <td style="width:68%;vertical-align:top;padding:3mm 6mm 0 0;">
            <table width="100%">
                @foreach(array_reverse((array) $document->legends) as $row)
                    <tr><td style="padding-bottom:1mm;">
                        @if ($row->code == "1000")
                            Son: <b>{{ F::amountInWords($row->value, $document->currency_type->description) }}</b>
                        @else
                            {{ $row->value }}
                        @endif
                    </td></tr>
                @endforeach
                @if ($document->detraction)
                    <tr><td style="padding-bottom:1mm;font-weight:bold;">Operación sujeta al Sistema de Pago de Obligaciones Tributarias</td></tr>
                @endif
                @if ($customer->department_id == 16)
                    <tr><td style="padding-bottom:1mm;font-weight:bold;">Bienes transferidos en la Amazonía para ser consumidos en la misma</td></tr>
                @endif

                @if($payment_condition === 'Crédito' && $fees->count())
                    <tr><td style="padding-top:3mm;font-weight:bold;">Crédito · pendiente de pago {{ $symbol }} {{ F::money($fees->sum('amount')) }}</td></tr>
                    @foreach($fees as $fee)
                        <tr><td style="padding-top:0.6mm;"><span style="{{ $muted }}">Cuota {{ $loop->iteration }} · {{ F::date($fee->date) }}</span>&nbsp;&nbsp;{{ $symbol }} {{ F::money($fee->amount) }}</td></tr>
                    @endforeach
                @endif

                @if($document->payment_method_type_id)
                    <tr><td style="padding-top:3mm;"><span style="{{ $muted }}">Medio de pago:</span> {{ $document->payment_method_type->description }}</td></tr>
                @endif
                @foreach($payments as $row)
                    <tr><td style="padding-top:{{ $loop->first && !$document->payment_method_type_id ? '3mm' : '0.6mm' }};"><span style="{{ $muted }}">{{ $row->payment_method_type->description }}{{ $row->reference ? ' · '.$row->reference : '' }}:</span> {{ $symbol }} {{ F::money($row->payment + $row->change) }}</td></tr>
                @endforeach

                @if(count($infos))
                    <tr><td style="padding-top:3mm;font-weight:bold;">Información adicional</td></tr>
                    @foreach($infos as $information)
                        <tr><td style="font-size:8pt;">{{ $information }}</td></tr>
                    @endforeach
                @endif

                @if(in_array($document->document_type->id, ['01', '03']) && $accounts->count())
                    <tr><td style="padding-top:3mm;font-weight:bold;">Cuentas para depósito</td></tr>
                    @foreach($accounts as $account)
                        <tr><td style="padding-top:0.8mm;font-size:7.5pt;">
                            <b>{{ $account->bank->description }}</b> <span style="{{ $muted }}">· {{ $account->currency_type->description }}</span><br>
                            N° {{ $account->number }}@if($account->cci) &nbsp;·&nbsp; CCI {{ $account->cci }}@endif
                        </td></tr>
                    @endforeach
                @endif

                <tr><td style="padding-top:3mm;font-size:8pt;{{ $muted }}">Vendedor: {{ $document->seller ? $document->seller->name : $document->user->name }}</td></tr>
            </table>
        </td>
        <td style="width:32%;vertical-align:top;text-align:center;padding-top:3mm;">
            @if($document->qr)
                <img src="data:image/png;base64, {{ $document->qr }}" style="width:{{ $qr_size }};height:{{ $qr_size }};" />
            @endif
            <div style="font-size:6.5pt;{{ $muted }}margin-top:1mm;">Hash: {{ $document->hash }}</div>
            <div style="font-size:7pt;margin-top:2mm;">Representación impresa de la {{ mb_strtolower($document->document_type->description) }}.</div>
            @if($compact)
                <div style="font-size:6.5pt;color:#666;">Consúltela en {!! url('/buscar') !!}</div>
            @endif
        </td>
    </tr>
</table>

@if ($document->terms_condition)
    <div style="margin-top:4mm;font-size:7.5pt;">
        <div style="font-weight:bold;margin-bottom:1mm;">Términos y condiciones</div>
        {!! $document->terms_condition !!}
    </div>
@endif

</div>
</body>
</html>
