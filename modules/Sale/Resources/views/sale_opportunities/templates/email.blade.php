@extends('tenant.templates.email.layout')
@section('title', 'Oportunidad de venta')
@section('preheader', 'Le enviamos su propuesta.')
@section('content')
    @php
        $__to = data_get($company, 'name');
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__to ? ', '.$__to : '' }}</p>
    <p style="margin:0;">Le enviamos nuestra propuesta.</p>
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Referencia' => \App\Support\MailBrand::number(data_get($sale_opportunity, 'prefix'), data_get($sale_opportunity, 'id')),
        'Fecha' => \App\Support\MailBrand::date(data_get($sale_opportunity, 'date_of_issue')),
        'Total' => \App\Support\MailBrand::money(data_get($sale_opportunity, 'total'), data_get($sale_opportunity, 'currency_type_id', 'PEN')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">Adjuntamos el PDF.</p>
@endsection
