@extends('tenant.templates.email.layout')
@section('title', 'Pedido')
@section('preheader', 'Le enviamos su pedido.')
@section('content')
    @php
        $__to = data_get($company, 'name');
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__to ? ', '.$__to : '' }}</p>
    <p style="margin:0;">Le enviamos su pedido.</p>
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Pedido' => \App\Support\MailBrand::number(data_get($order_note, 'prefix'), data_get($order_note, 'id')),
        'Fecha' => \App\Support\MailBrand::date(data_get($order_note, 'date_of_issue')),
        'Entrega' => \App\Support\MailBrand::date(data_get($order_note, 'delivery_date')),
        'Total' => \App\Support\MailBrand::money(data_get($order_note, 'total'), data_get($order_note, 'currency_type_id', 'PEN')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">Adjuntamos el PDF.</p>
@endsection
