<html xmlns="http://www.w3.org/1999/xhtml">

<body
    style="margin: 0; padding: 20px; font-family: Arial, sans-serif; font-size: 13px; font-weight: 400; word-break: break-word; color: #555; line-height: 20px;">
    @php
        $isOwner = ($document->mail_type ?? 'cash') === 'owner';
    @endphp
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%"
        style="max-width: 520px; margin: 40px auto; border-collapse: collapse; border-radius: 2px; overflow: hidden;">
        <tbody>
            <tr bgcolor="#2563eb" height="80px">
                <td align="center" style="color: #fff; font-size: 18px; font-weight: 600; letter-spacing: 1px;">
                    {{ $isOwner ? 'Nuevo pedido en la tienda virtual' : 'Recibimos su pedido' }}
                </td>
            </tr>
            <tr bgcolor="#f9f9f9">
                <td style="padding: 32px;">
                    <p style="margin: 0 0 12px; color: #1d4ed8; font-size: 18px;">
                        {{ $isOwner ? 'Pedido '.($document->order_number ?? '') : 'Hola '.$document->client }}
                    </p>
                    <p style="margin: 0 0 16px; color: #333;">
                        @if($isOwner)
                            Cliente: <b>{{ $document->client }}</b>. El pedido está pendiente de verificación de pago.
                        @else
                            Su pedido {{ $document->order_number ?? '' }} fue registrado y está pendiente de pago.
                            Le contactaremos para coordinar el pago y la entrega. Este mensaje no es una constancia de pago.
                        @endif
                    </p>

                    <table cellpadding="4" cellspacing="0" width="100%">
                        <thead>
                            <tr>
                                <th style="color: #1d4ed8;" align="left">Producto</th>
                                <th style="color: #1d4ed8;" align="center">Cantidad</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($document->items as $item)
                                <tr>
                                    <td>{{ $item['description'] ?? ($item['name'] ?? '') }}</td>
                                    <td align="center">{{ $item['cantidad'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <p style="margin: 16px 0;">Total: <b>S/ {{ $document->total }}</b></p>

                    @unless($isOwner)
                        @inject('userAdmin', 'App\Services\UserAdminService')
                        @php($config = $userAdmin->getUserAdmin())
                        <p style="margin: 0; color: #333;">
                            Contacto de la tienda:
                            {{ optional($config)->information_contact_email }}
                            {{ optional($config)->information_contact_phone }}
                        </p>
                    @endunless
                </td>
            </tr>
        </tbody>
    </table>
</body>

</html>
