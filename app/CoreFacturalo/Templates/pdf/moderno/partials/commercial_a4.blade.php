{{--
    Plantilla común A4/A5 para documentos comerciales (nota de venta, cotización, pedido, compras, contratos...).
    Mismo diseño que la factura. Cada plantilla arma $d con los datos ya normalizados:
      establishment, title, number, anulado, party_label, party, party_extra[[label,value]], meta[[label,value]],
      refs[[label,value]], notes[[title,text]], items (PdfFormat::itemRows), show_prices, totals[label=>monto],
      grand, symbol, words, payments[[label,monto]], balance, accounts, seller, legend, terms, compact.
--}}
@php
    use App\Support\PdfFormat as F;

    $establishment = $d['establishment'];
    $compact = $d['compact'] ?? false;
    $symbol = $d['symbol'] ?? 'S/';
    $show_prices = $d['show_prices'] ?? true;
    $items = $d['items'] ?? [];
    $party = $d['party'] ?? null;
    $anonymous = $party ? F::isAnonymous($party) : true;
    $company_address = F::address($establishment);
    $party_address = ($party && !$anonymous) ? F::address($party) : '';
    $accounts = ($d['accounts'] ?? false) ? \App\Models\Tenant\BankAccount::where('show_in_documents', true)->get() : collect();

    $has_model = $has_lot = $has_series = $has_discount = false;
    foreach ($items as $it) {
        $has_model = $has_model || $it['model'] !== '';
        $has_lot = $has_lot || $it['lot'] !== '';
        $has_series = $has_series || count($it['series']) > 0;
        $has_discount = $has_discount || $it['discount'] > 0;
    }

    $fs = $compact ? '7.5pt' : '8.5pt';
    $gap = $compact ? '3mm' : '6mm';
    $muted = 'color:#555;';
    $th = 'padding:'.($compact ? '1.2mm' : '2mm').' 1.5mm;background:#f2f2f2;border-bottom:0.3mm solid #000;font-size:7.5pt;font-weight:bold;';
    $td = 'padding:'.($compact ? '0.9mm' : '1.8mm').' 1.5mm;border-bottom:0.15mm solid #ccc;vertical-align:top;';
    $k = 'padding:'.($compact ? '0.4mm' : '0.8mm').' 0;color:#555;vertical-align:top;white-space:nowrap;width:30mm;';
    $v = 'padding:'.($compact ? '0.4mm' : '0.8mm').' 0;vertical-align:top;';
@endphp
<html>
<head></head>
<body>
@if($d['anulado'] ?? false)
    <div style="position:absolute;top:95mm;left:15mm;width:180mm;text-align:center;">
        <img src="data:{{mime_content_type(public_path("status_images".DIRECTORY_SEPARATOR."anulado.png"))}};base64, {{base64_encode(file_get_contents(public_path("status_images".DIRECTORY_SEPARATOR."anulado.png")))}}" alt="anulado">
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
            <div style="font-size:8pt;{{ $muted }}">
                @if($company_address){{ $company_address }}<br>@endif
                @if(F::filled(data_get($establishment, 'telephone')))Tel. {{ $establishment->telephone }}<br>@endif
                @if(F::filled(data_get($establishment, 'web_address'))){{ $establishment->web_address }}@endif
            </div>
        </td>
        <td style="width:62mm;vertical-align:middle;">
            <table width="100%" style="border:0.35mm solid #000;">
                <tr><td style="text-align:center;padding:2.5mm 2mm 0.5mm;font-size:10pt;font-weight:bold;">RUC {{ $company->number }}</td></tr>
                <tr><td style="text-align:center;padding:0.5mm 2mm;font-size:9.5pt;font-weight:bold;">{{ $d['title'] }}</td></tr>
                <tr><td style="text-align:center;padding:0.5mm 2mm 2.5mm;font-size:12pt;font-weight:bold;">{{ $d['number'] }}</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- Cliente / proveedor y datos --}}
<table width="100%" style="margin-top:{{ $gap }};border-top:0.3mm solid #000;border-bottom:0.15mm solid #ccc;">
    <tr>
        <td style="width:58%;vertical-align:top;padding:2mm 4mm 2mm 0;">
            <table width="100%">
                @if($party)
                    <tr><td style="{{ $k }}">{{ $d['party_label'] ?? 'Cliente' }}</td><td style="{{ $v }}font-weight:bold;">{{ $party->name }}</td></tr>
                    @unless($anonymous)
                        <tr><td style="{{ $k }}">{{ F::identityLabel($party) }}</td><td style="{{ $v }}">{{ $party->number }}</td></tr>
                    @endunless
                    @if($party_address)
                        <tr><td style="{{ $k }}">Dirección</td><td style="{{ $v }}">{{ $party_address }}</td></tr>
                    @endif
                @endif
                @foreach(($d['party_extra'] ?? []) as $row)
                    @if(F::filled($row[1]))
                        <tr><td style="{{ $k }}">{{ $row[0] }}</td><td style="{{ $v }}">{{ $row[1] }}</td></tr>
                    @endif
                @endforeach
            </table>
        </td>
        <td style="width:42%;vertical-align:top;padding:2mm 0;">
            <table width="100%">
                @foreach(($d['meta'] ?? []) as $row)
                    @if(F::filled($row[1]))
                        <tr><td style="{{ $k }}">{{ $row[0] }}</td><td style="{{ $v }}{{ ($row[2] ?? false) ? 'font-weight:bold;' : '' }}">{{ $row[1] }}</td></tr>
                    @endif
                @endforeach
            </table>
        </td>
    </tr>
</table>

{{-- Referencias --}}
@php $refs = array_values(array_filter($d['refs'] ?? [], function ($r) { return F::filled($r[1]); })); @endphp
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
        @if($show_prices)
            <th style="{{ $th }}text-align:right;width:12%;">P. UNIT.</th>
            @if($has_discount)<th style="{{ $th }}text-align:right;width:9%;">DSCTO.</th>@endif
            <th style="{{ $th }}text-align:right;width:13%;">IMPORTE</th>
        @endif
    </tr>
    </thead>
    <tbody>
    @foreach($items as $it)
        <tr>
            <td style="{{ $td }}text-align:center;">{{ $it['qty'] }}</td>
            <td style="{{ $td }}text-align:center;">{{ $it['unit'] }}</td>
            <td style="{{ $td }}">
                {!! $it['description'] !!}
                @foreach($it['extra'] as $line)
                    <br><span style="font-size:7pt;{{ $muted }}">{!! $line !!}</span>
                @endforeach
            </td>
            @if($has_model)<td style="{{ $td }}">{{ $it['model'] }}</td>@endif
            @if($has_lot)<td style="{{ $td }}text-align:center;">{{ $it['lot'] }}</td>@endif
            @if($has_series)<td style="{{ $td }}text-align:center;font-size:7pt;">{!! implode('<br>', array_map('e', $it['series'])) !!}</td>@endif
            @if($show_prices)
                <td style="{{ $td }}text-align:right;">{{ F::money($it['unit_price']) }}</td>
                @if($has_discount)<td style="{{ $td }}text-align:right;">{{ $it['discount'] > 0 ? F::money($it['discount']) : '' }}</td>@endif
                <td style="{{ $td }}text-align:right;">{{ F::money($it['total']) }}</td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table>

{{-- Totales --}}
@if($show_prices && isset($d['grand']))
    <table width="100%" style="margin-top:2mm;">
        <tr>
            <td style="width:55%;"></td>
            <td style="width:45%;">
                <table width="100%">
                    @foreach(($d['totals'] ?? []) as $label => $amount)
                        <tr>
                            <td style="padding:0.8mm 0;{{ $muted }}">{{ $label }}</td>
                            <td style="padding:0.8mm 0;text-align:right;">{{ $symbol }} {{ F::money($amount) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td style="padding:2mm 0;border-top:0.3mm solid #000;font-size:11pt;font-weight:bold;">{{ $d['grand_label'] ?? 'TOTAL' }}</td>
                        <td style="padding:2mm 0;border-top:0.3mm solid #000;font-size:11pt;font-weight:bold;text-align:right;">{{ $symbol }} {{ F::money($d['grand']) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endif

{{-- Pie --}}
<table width="100%" style="margin-top:4mm;border-top:0.15mm solid #ccc;">
    <tr><td style="vertical-align:top;padding-top:3mm;">
        <table width="100%">
            @if($d['words'] ?? null)
                <tr><td style="padding-bottom:1mm;">Son: <b>{{ $d['words'] }}</b></td></tr>
            @endif
            @foreach(($d['notes'] ?? []) as $note)
                @if(F::filled($note[1]))
                    <tr><td style="padding-top:2mm;font-weight:bold;">{{ $note[0] }}</td></tr>
                    <tr><td>{!! nl2br(e($note[1])) !!}</td></tr>
                @endif
            @endforeach
            @if(count($d['payments'] ?? []))
                <tr><td style="padding-top:3mm;font-weight:bold;">Pagos</td></tr>
                @foreach($d['payments'] as $p)
                    <tr><td style="padding-top:0.6mm;"><span style="{{ $muted }}">{{ $p[0] }}:</span> {{ $symbol }} {{ F::money($p[1]) }}</td></tr>
                @endforeach
            @endif
            @if(isset($d['balance']) && $d['balance'] !== null)
                <tr><td style="padding-top:1mm;"><b>Saldo: {{ $symbol }} {{ F::money($d['balance']) }}</b></td></tr>
            @endif
            @if($accounts->count())
                <tr><td style="padding-top:3mm;font-weight:bold;">Cuentas para depósito</td></tr>
                @foreach($accounts as $account)
                    <tr><td style="padding-top:0.8mm;font-size:7.5pt;">
                        <b>{{ $account->bank->description }}</b> <span style="{{ $muted }}">· {{ $account->currency_type->description }}</span><br>
                        N° {{ $account->number }}@if($account->cci) &nbsp;·&nbsp; CCI {{ $account->cci }}@endif
                    </td></tr>
                @endforeach
            @endif
            @if($d['seller'] ?? null)
                <tr><td style="padding-top:3mm;font-size:8pt;{{ $muted }}">{{ $d['seller_label'] ?? 'Vendedor' }}: {{ $d['seller'] }}</td></tr>
            @endif
            @if($d['legend'] ?? null)
                <tr><td style="padding-top:3mm;font-size:7.5pt;{{ $muted }}">{{ $d['legend'] }}</td></tr>
            @endif
        </table>
    </td></tr>
</table>

@if($d['terms'] ?? null)
    <table width="100%" style="margin-top:4mm;font-size:7.5pt;">
        <tr><td style="font-weight:bold;padding-bottom:1mm;">Términos y condiciones</td></tr>
        <tr><td>{!! $d['terms'] !!}</td></tr>
    </table>
@endif

</div>
</body>
</html>
