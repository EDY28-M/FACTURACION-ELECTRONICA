@extends('tenant.templates.email.layout')
@section('title', 'Restablecer contraseña')
@section('preheader', 'Restablezca su contraseña.')
@section('content')
    <p style="margin:0 0 4px;font-size:16px;font-weight:600;color:#18181b;">Restablecer contraseña</p>
    <p style="margin:0;">Recibimos una solicitud para restablecer la contraseña de su cuenta.</p>
    <p style="margin:22px 0;">
        <a href="{{ $url }}" style="display:inline-block;padding:11px 22px;background:#18181b;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;border-radius:4px;">Restablecer contraseña</a>
    </p>
    <p style="margin:0 0 10px;font-size:13px;color:#71717a;">El enlace vence en 60 minutos. Si no fue usted, ignore este mensaje.</p>
    <p style="margin:0;font-size:12px;color:#a1a1aa;word-break:break-all;">Si el botón no abre, copie este enlace: {{ $url }}</p>
@endsection
