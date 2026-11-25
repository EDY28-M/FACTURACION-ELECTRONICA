@extends('tenant.templates.email.layout')
@section('title', 'Solicitud de cotización')
@section('preheader', 'Le solicitamos una cotización.')
@section('content')
    @php
        $__to = data_get($company, 'name');
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__to ? ', '.$__to : '' }}</p>
    <p style="margin:0;">Le solicitamos una cotización de los productos del documento adjunto.</p>
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Solicitud' => \App\Support\MailBrand::number(data_get($purchase_quotation, 'prefix'), data_get($purchase_quotation, 'id')),
        'Fecha' => \App\Support\MailBrand::date(data_get($purchase_quotation, 'date_of_issue')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">Adjuntamos el PDF.</p>
@endsection
