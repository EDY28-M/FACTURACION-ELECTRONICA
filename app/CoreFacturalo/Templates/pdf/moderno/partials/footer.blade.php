@php
    $path_style = app_path('CoreFacturalo'.DIRECTORY_SEPARATOR.'Templates'.DIRECTORY_SEPARATOR.'pdf'.DIRECTORY_SEPARATOR.'style.css');
@endphp
<head>
    <link href="{{ $path_style }}" rel="stylesheet" />
</head>
<body>
{{-- Solo en comprobantes electrónicos (y guías): una nota de venta o cotización no se consulta en SUNAT. --}}
@if(is_null($document) || $document instanceof \App\Models\Tenant\Document)
<table class="full-width">
    <tr>
        <td style="text-align:center;font-family:dejavusans;font-size:7pt;color:#666;">Consulte este comprobante en {!! url('/buscar') !!}</td>
    </tr>
</table>
@endif
</body>