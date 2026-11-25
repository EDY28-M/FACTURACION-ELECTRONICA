@extends('tenant.templates.email.layout')
@section('title', 'Compra')
@section('preheader', 'Le enviamos el detalle de nuestra compra.')
@section('content')
    @php
        $__to = data_get($purchase, 'supplier.name');
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__to ? ', '.$__to : '' }}</p>
    <p style="margin:0;">Le enviamos el detalle de nuestra compra.</p>
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Compra' => \App\Support\MailBrand::number(data_get($purchase, 'series'), data_get($purchase, 'number')),
        'Fecha' => \App\Support\MailBrand::date(data_get($purchase, 'date_of_issue')),
        'Total' => \App\Support\MailBrand::money(data_get($purchase, 'total'), data_get($purchase, 'currency_type_id', 'PEN')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">Adjuntamos el PDF.</p>
@endsection
