@extends('tenant.templates.email.layout')
@section('title', 'Guía de remisión')
@section('preheader', 'Le enviamos su guía de remisión.')
@section('content')
    @php
        $__to = data_get($dispatch, 'customer.name');
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__to ? ', '.$__to : '' }}</p>
    <p style="margin:0;">Le enviamos su guía de remisión.</p>
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Guía' => \App\Support\MailBrand::number(data_get($dispatch, 'series'), data_get($dispatch, 'number')),
        'Fecha' => \App\Support\MailBrand::date(data_get($dispatch, 'date_of_issue')),
        'Traslado' => \App\Support\MailBrand::date(data_get($dispatch, 'date_of_shipping')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">Adjuntamos el PDF.</p>
@endsection
