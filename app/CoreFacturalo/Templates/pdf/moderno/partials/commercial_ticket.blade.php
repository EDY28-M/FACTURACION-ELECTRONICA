{{--
    Plantilla común en ticket para documentos comerciales. Recibe el mismo $d que commercial_a4,
    más 'paper' (ancho en mm: 78 por defecto, 56 o 45 para tickets angostos).
--}}
@php
    use App\Support\PdfFormat as F;

    $establishment = $d['establishment'];
    $paper = $d['paper'] ?? 78;
    $symbol = $d['symbol'] ?? 'S/';
    $show_prices = $d['show_prices'] ?? true;
    $items = $d['items'] ?? [];
    $party = $d['party'] ?? null;
    $anonymous = $party ? F::isAnonymous($party) : true;
    $company_address = F::address($establishment);
    $party_address = ($party && !$anonymous) ? F::address($party) : '';
    $accounts = ($d['accounts'] ?? false) ? \App\Models\Tenant\BankAccount::where('show_in_documents', true)->get() : collect();

    $fs = $paper <= 45 ? '6.5pt' : ($paper <= 56 ? '7pt' : '8pt');
    $fs_name = $paper <= 56 ? '9pt' : '10.5pt';
    $fs_number = $paper <= 45 ? '9pt' : ($paper <= 56 ? '10pt' : '12pt');
    $fs_total = $paper <= 56 ? '8.5pt' : '10pt';
    $logo_w = ($paper - 30).'mm';

    $line = 'border-top:0.2mm solid #000;';
    $thin = 'border-top:0.15mm solid #999;';
    $k = 'padding:0.6mm 0;color:#444;vertical-align:top;';
    $v = 'padding:0.6mm 0 0.6mm 2mm;vertical-align:top;';
    $vr = 'padding:0.6mm 0 0.6mm 1.5mm;vertical-align:top;text-align:right;white-space:nowrap;';
@endphp
<html>
<head></head>
<body>
@if($d['anulado'] ?? false)
    <div style="position:absolute;top:70mm;left:2mm;width:{{ $paper - 4 }}mm;text-align:center;">
        <img src="data:{{mime_content_type(public_path("status_images".DIRECTORY_SEPARATOR."anulado.png"))}};base64, {{base64_encode(file_get_contents(public_path("status_images".DIRECTORY_SEPARATOR."anulado.png")))}}" alt="anulado">
    </div>
@endif
<div style="font-family:dejavusanscondensed;font-size:{{ $fs }};color:#000;">

@if($company->logo)
    <div style="text-align:center;padding-top:3mm;">
        <img src="data:{{mime_content_type(public_path("storage/uploads/logos/{$company->logo}"))}};base64, {{base64_encode(file_get_contents(public_path("storage/uploads/logos/{$company->logo}")))}}" alt="{{$company->name}}" style="max-width:{{ $logo_w }};max-height:20mm;">
    </div>
@endif

<table width="100%" style="margin-top:2mm;">
    <tr><td style="text-align:center;font-size:{{ $fs_name }};font-weight:bold;">{{ $company->name }}</td></tr>
    @if(F::filled($company->trade_name) && mb_strtolower(trim($company->trade_name)) !== mb_strtolower(trim($company->name)))
        <tr><td style="text-align:center;">{{ $company->trade_name }}</td></tr>
    @endif
    <tr><td style="text-align:center;font-weight:bold;">RUC {{ $company->number }}</td></tr>
    @if($company_address)
        <tr><td style="text-align:center;font-size:7.5pt;color:#333;">{{ $company_address }}</td></tr>
    @endif
    @if(F::filled(data_get($establishment, 'telephone')))
        <tr><td style="text-align:center;font-size:7.5pt;color:#333;">Tel. {{ $establishment->telephone }}</td></tr>
    @endif
</table>

<table width="100%" style="margin-top:2.5mm;">
    <tr><td style="{{ $line }}padding-top:2mm;text-align:center;font-size:8.5pt;font-weight:bold;">{{ $d['title'] }}</td></tr>
    <tr><td style="padding-bottom:2mm;text-align:center;font-size:{{ $fs_number }};font-weight:bold;">{{ $d['number'] }}</td></tr>
</table>

<table width="100%" style="{{ $line }}">
    @php $first = true; @endphp
    @foreach(($d['meta'] ?? []) as $row)
        @if(F::filled($row[1]))
            <tr><td style="{{ $k }}{{ $first ? 'padding-top:1.5mm;' : '' }}">{{ $row[0] }}</td><td style="{{ $v }}{{ $first ? 'padding-top:1.5mm;' : '' }}{{ ($row[2] ?? false) ? 'font-weight:bold;' : '' }}">{{ $row[1] }}</td></tr>
            @php $first = false; @endphp
        @endif
    @endforeach
    @if($party)
        <tr><td style="{{ $k }}">{{ $d['party_label'] ?? 'Cliente' }}</td><td style="{{ $v }}">{{ $party->name }}</td></tr>
        @unless($anonymous)
            <tr><td style="{{ $k }}">{{ F::identityLabel($party) }}</td><td style="{{ $v }}">{{ $party->number }}</td></tr>
        @endunless
        @if($party_address)
            <tr><td style="{{ $k }}">Dirección</td><td style="{{ $v }}">{{ $party_address }}</td></tr>
        @endif
    @endif
    @foreach(array_merge($d['party_extra'] ?? [], $d['refs'] ?? []) as $row)
        @if(F::filled($row[1]))
            <tr><td style="{{ $k }}">{{ $row[0] }}</td><td style="{{ $v }}">{{ $row[1] }}</td></tr>
        @endif
    @endforeach
</table>

<table width="100%" style="margin-top:2mm;">
    <tr>
        <td style="{{ $line }}border-bottom:0.2mm solid #000;padding:1mm 0;font-size:7.5pt;font-weight:bold;">DESCRIPCIÓN</td>
        <td style="{{ $line }}border-bottom:0.2mm solid #000;padding:1mm 0;font-size:7.5pt;font-weight:bold;text-align:right;">{{ $show_prices ? 'IMPORTE' : 'CANT.' }}</td>
    </tr>
    @foreach($items as $it)
        <tr><td colspan="2" style="padding-top:1.5mm;font-weight:bold;">{!! $it['description'] !!}</td></tr>
        @if(count($it['extra']) || $it['lot'] !== '' || count($it['series']))
            <tr><td colspan="2" style="font-size:7pt;color:#444;">
                {!! implode('<br>', $it['extra']) !!}
                @if($it['lot'] !== '')<br>Lote: {{ $it['lot'] }}@endif
                @if(count($it['series']))<br>Serie: {{ implode(', ', $it['series']) }}@endif
            </td></tr>
        @endif
        <tr>
            @if($show_prices)
                <td style="padding-bottom:1.5mm;color:#333;border-bottom:0.2mm dotted #999;">{{ $it['qty'] }} {{ $it['unit'] }} x {{ F::money($it['unit_price']) }}</td>
                <td style="padding-bottom:1.5mm;text-align:right;font-weight:bold;border-bottom:0.2mm dotted #999;white-space:nowrap;">{{ F::money($it['total']) }}</td>
            @else
                <td style="padding-bottom:1.5mm;border-bottom:0.2mm dotted #999;"></td>
                <td style="padding-bottom:1.5mm;text-align:right;font-weight:bold;border-bottom:0.2mm dotted #999;white-space:nowrap;">{{ $it['qty'] }} {{ $it['unit'] }}</td>
            @endif
        </tr>
    @endforeach
</table>

@if($show_prices && isset($d['grand']))
    <table width="100%" style="margin-top:1.5mm;">
        @foreach(($d['totals'] ?? []) as $label => $amount)
            <tr>
                <td style="text-align:right;padding:0.4mm 0;color:#333;">{{ $label }}</td>
                <td style="text-align:right;padding:0.4mm 0;white-space:nowrap;">{{ $symbol }} {{ F::money($amount) }}</td>
            </tr>
        @endforeach
        <tr>
            <td style="text-align:right;padding:1.2mm 0;font-size:{{ $fs_total }};font-weight:bold;{{ $line }}">{{ $d['grand_label'] ?? 'TOTAL' }}</td>
            <td style="text-align:right;padding:1.2mm 0;font-size:{{ $fs_total }};font-weight:bold;white-space:nowrap;{{ $line }}">{{ $symbol }} {{ F::money($d['grand']) }}</td>
        </tr>
    </table>
@endif

@if($d['words'] ?? null)
    <table width="100%" style="margin-top:1mm;"><tr><td style="padding:0.6mm 0;">Son: <b>{{ $d['words'] }}</b></td></tr></table>
@endif

@if(count($d['payments'] ?? []) || (isset($d['balance']) && $d['balance'] !== null))
    <table width="100%" style="{{ $thin }}margin-top:1.5mm;">
        @foreach(($d['payments'] ?? []) as $p)
            <tr><td style="{{ $k }}{{ $loop->first ? 'padding-top:1.5mm;' : '' }}">{{ $p[0] }}</td><td style="{{ $vr }}{{ $loop->first ? 'padding-top:1.5mm;' : '' }}">{{ $symbol }} {{ F::money($p[1]) }}</td></tr>
        @endforeach
        @if(isset($d['balance']) && $d['balance'] !== null)
            <tr><td style="{{ $k }}font-weight:bold;color:#000;">Saldo</td><td style="{{ $vr }}font-weight:bold;">{{ $symbol }} {{ F::money($d['balance']) }}</td></tr>
        @endif
    </table>
@endif

@foreach(($d['notes'] ?? []) as $note)
    @if(F::filled($note[1]))
        <table width="100%" style="{{ $thin }}margin-top:1.5mm;">
            <tr><td style="padding-top:1.5mm;font-weight:bold;">{{ $note[0] }}</td></tr>
            <tr><td style="font-size:7.5pt;">{!! nl2br(e($note[1])) !!}</td></tr>
        </table>
    @endif
@endforeach

@if($accounts->count())
    <table width="100%" style="{{ $thin }}margin-top:1.5mm;font-size:7pt;">
        <tr><td style="padding-top:1.5mm;font-weight:bold;font-size:7.5pt;">Cuentas para depósito</td></tr>
        @foreach($accounts as $account)
            <tr><td style="padding-top:0.8mm;">
                <b>{{ $account->bank->description }}</b> · {{ $account->currency_type->description }}<br>
                N° {{ $account->number }}@if($account->cci) · CCI {{ $account->cci }}@endif
            </td></tr>
        @endforeach
    </table>
@endif

<table width="100%" style="{{ $line }}margin-top:2mm;">
    @if($d['legend'] ?? null)
        <tr><td style="text-align:center;font-size:7pt;padding-top:1.5mm;">{{ $d['legend'] }}</td></tr>
    @endif
    @if($d['seller'] ?? null)
        <tr><td style="text-align:center;font-size:7pt;color:#444;padding-top:1.5mm;">{{ $d['seller_label'] ?? 'Atendido por' }}: {{ $d['seller'] }}</td></tr>
    @endif
</table>

@if($d['terms'] ?? null)
    <table width="100%" style="{{ $thin }}margin-top:2mm;font-size:7pt;">
        <tr><td style="padding-top:1.5mm;font-weight:bold;">Términos y condiciones</td></tr>
        <tr><td>{!! $d['terms'] !!}</td></tr>
    </table>
@endif

</div>
</body>
</html>
