@extends('tenant.templates.email.layout')
@section('title', (data_get($document, 'mail_type') ?? 'cash') === 'owner' ? 'Nuevo pedido' : 'Recibimos su pedido')
@section('preheader', (data_get($document, 'mail_type') ?? 'cash') === 'owner' ? 'Nuevo pedido en la tienda virtual.' : 'Recibimos su pedido.')
@section('content')
    @inject('userAdmin', 'App\Services\UserAdminService')
    @php
        $__owner = (data_get($document, 'mail_type') ?? 'cash') === 'owner';
        $__no = data_get($document, 'order_number');
        $__client = data_get($document, 'client');
        $__cfg = null;
        try { $__cfg = $userAdmin->getUserAdmin(); } catch (\Throwable $e) {}
        $__contact = implode('  ·  ', array_filter([optional($__cfg)->information_contact_email, optional($__cfg)->information_contact_phone]));
    @endphp
    @if($__owner)
        <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Nuevo pedido{{ $__no ? ' '.$__no : '' }}</p>
        <p style="margin:0;">Cliente: {{ $__client }}. Pendiente de verificar el pago.</p>
    @else
        <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Hola{{ $__client ? ', '.$__client : '' }}</p>
        <p style="margin:0;">Recibimos su pedido{{ $__no ? ' '.$__no : '' }}. Está pendiente de pago; le contactaremos para coordinar el pago y la entrega.</p>
    @endif
    @include('tenant.templates.email.partials.items', ['items' => data_get($document, 'items', [])])
    @include('tenant.templates.email.partials.rows', ['rows' => [
        'Total' => \App\Support\MailBrand::money(data_get($document, 'total')),
    ]])
    @unless($__owner)
        <p style="margin:0;font-size:13px;color:#71717a;">Este mensaje no es una constancia de pago.{{ $__contact ? ' Contacto: '.$__contact : '' }}</p>
    @endunless
@endsection
