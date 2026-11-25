@extends('tenant.templates.email.layout')
@section('title', 'Orden de compra')
@section('preheader', 'Le enviamos nuestra orden de compra.')
@section('content')
    @php
        $__to = data_get($purchase_order, 'supplier.name');
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__to ? ', '.$__to : '' }}</p>
    <p style="margin:0;">Le enviamos nuestra orden de compra.</p>
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Orden' => \App\Support\MailBrand::number(data_get($purchase_order, 'prefix'), data_get($purchase_order, 'id')),
        'Fecha' => \App\Support\MailBrand::date(data_get($purchase_order, 'date_of_issue')),
        'Entrega' => \App\Support\MailBrand::date(data_get($purchase_order, 'date_of_due')),
        'Total' => \App\Support\MailBrand::money(data_get($purchase_order, 'total'), data_get($purchase_order, 'currency_type_id', 'PEN')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">Adjuntamos el PDF.</p>
@endsection
