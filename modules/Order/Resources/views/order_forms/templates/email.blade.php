@extends('tenant.templates.email.layout')
@section('title', 'Orden de pedido')
@section('preheader', 'Le enviamos su orden de pedido.')
@section('content')
    @php
        $__to = data_get($order_form, 'customer.name');
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__to ? ', '.$__to : '' }}</p>
    <p style="margin:0;">Le enviamos su orden de pedido.</p>
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Orden' => \App\Support\MailBrand::number(data_get($order_form, 'prefix'), data_get($order_form, 'id')),
        'Fecha' => \App\Support\MailBrand::date(data_get($order_form, 'date_of_issue')),
        'Envío' => \App\Support\MailBrand::date(data_get($order_form, 'date_of_shipping')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">Adjuntamos el PDF.</p>
@endsection
