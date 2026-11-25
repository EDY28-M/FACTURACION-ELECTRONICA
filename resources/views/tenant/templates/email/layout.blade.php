@php
    try {
        $__co = \App\Models\Tenant\Company::first();
        $__est = \App\Models\Tenant\Establishment::first();
    } catch (\Throwable $e) {
        $__co = null;
        $__est = null;
    }
    $__brand = $__co ? ($__co->trade_name ?: $__co->name) : config('mail.from.name');
    $__ruc = ($__co && $__co->number) ? 'RUC '.$__co->number : null;
    $__tel = optional($__est)->telephone;
    $__tel = ($__tel && trim($__tel) !== '-') ? $__tel : null;
    $__mail = optional($__est)->email;
    $__logo = ($__co && $__co->logo) ? asset('storage/uploads/logos/'.$__co->logo) : null;
    $__foot = implode('  ·  ', array_filter([$__brand, $__ruc, $__tel, $__mail]));
    $__font = "-apple-system,'Segoe UI',Helvetica,Arial,sans-serif";
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', $__brand)</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">@yield('preheader')</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="max-width:560px;background:#ffffff;border:1px solid #e4e4e7;border-radius:6px;">
                <tr>
                    <td style="padding:24px 28px 0 28px;font-family:{!! $__font !!};font-size:15px;font-weight:600;line-height:1.3;color:#18181b;">
                        @if($__logo)
                            <img src="{{ $__logo }}" alt="{{ $__brand }}" height="36" style="display:block;border:0;max-height:36px;width:auto;">
                        @else
                            {{ $__brand }}
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px 28px 28px;font-family:{!! $__font !!};font-size:14px;line-height:1.6;color:#3f3f46;">
                        @yield('content')
                    </td>
                </tr>
            </table>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                <tr>
                    <td align="center" style="padding:14px 8px 0 8px;font-family:{!! $__font !!};font-size:12px;line-height:1.5;color:#a1a1aa;">
                        {{ $__foot }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
