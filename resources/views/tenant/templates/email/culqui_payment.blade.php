@extends('tenant.templates.email.layout')
@section('title', 'Constancia de pago')
@section('preheader', 'Recibimos su pago.')
@section('content')
    @inject('userAdmin', 'App\Services\UserAdminService')
    @php
        $__client = data_get($document, 'client');
        $__cfg = null;
        try { $__cfg = $userAdmin->getUserAdmin(); } catch (\Throwable $e) {}
        $__contact = implode('  ·  ', array_filter([
            optional($__cfg)->information_contact_name,
            optional($__cfg)->information_contact_email,
            optional($__cfg)->information_contact_phone,
        ]));
    @endphp
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__client ? ', '.$__client : '' }}</p>
    <p style="margin:0;">Recibimos su pago. Gracias por su compra.</p>
    @include('tenant.templates.email.partials.items', ['items' => data_get($document, 'items', [])])
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Monto pagado' => \App\Support\MailBrand::money(data_get($document, 'total')),
    ]])
    <p style="margin:0;font-size:13px;color:#71717a;">{{ $__contact ? 'Contacto: '.$__contact.'. ' : '' }}Puede responder a este correo ante cualquier duda.</p>
@endsection
