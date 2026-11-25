@extends('tenant.templates.email.layout')
@section('title', 'Contrato')
@section('preheader', 'Le enviamos su contrato.')
@section('content')
    @php
        $__to = data_get($company, 'name');
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__to ? ', '.$__to : '' }}</p>
    <p style="margin:0;">Le enviamos su contrato.</p>
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Contrato' => \App\Support\MailBrand::number(data_get($contract, 'prefix'), data_get($contract, 'id')),
        'Fecha' => \App\Support\MailBrand::date(data_get($contract, 'date_of_issue')),
        'Vence' => \App\Support\MailBrand::date(data_get($contract, 'date_of_due')),
        'Total' => \App\Support\MailBrand::money(data_get($contract, 'total'), data_get($contract, 'currency_type_id', 'PEN')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">Adjuntamos el PDF.</p>
@endsection
