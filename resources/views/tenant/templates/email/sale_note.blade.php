@extends('tenant.templates.email.layout')
@section('title', 'Nota de venta')
@section('preheader', 'Le enviamos su nota de venta.')
@section('content')
    @php
        $__to = data_get($document, 'customer.name');
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__to ? ', '.$__to : '' }}</p>
    <p style="margin:0;">Le enviamos su nota de venta.</p>
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Nota de venta' => \App\Support\MailBrand::number(data_get($document, 'series'), data_get($document, 'number')),
        'Fecha' => \App\Support\MailBrand::date(data_get($document, 'date_of_issue')),
        'Total' => \App\Support\MailBrand::money(data_get($document, 'total'), data_get($document, 'currency_type_id', 'PEN')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">Adjuntamos el PDF.</p>
@endsection
