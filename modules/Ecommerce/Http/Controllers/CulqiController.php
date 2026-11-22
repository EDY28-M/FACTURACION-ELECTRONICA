<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\Tenant\CulqiEmail;
use App\Models\Tenant\ConfigurationEcommerce;
use App\Models\Tenant\Order;
use App\Services\Payments\CartPricing;
use App\Services\Payments\EcommerceCheckout;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

class CulqiController extends Controller
{
    public function payment(Request $request, EcommerceCheckout $checkout)
    {
        $customer = json_decode((string) $request->customer, true);
        $validator = Validator::make(is_array($customer) ? $customer : [], [
            'telefono' => 'required|numeric',
            'direccion' => 'required',
            'codigo_tipo_documento_identidad' => 'required|numeric',
            'numero_documento' => 'required|numeric',
            'identity_document_type_id' => 'required|numeric'
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'installments' => 'nullable|integer',
            'device_finger_print_id' => 'nullable|string|max:100',
            'authentication_3DS' => 'nullable|array',
            'authentication_3DS.eci' => 'nullable|string|max:255',
            'authentication_3DS.xid' => 'nullable|string|max:255',
            'authentication_3DS.cavv' => 'nullable|string|max:255',
            'authentication_3DS.protocolVersion' => 'nullable|string|max:255',
            'authentication_3DS.directoryServerTransactionId' => 'nullable|string|max:255',
        ]);

        if (auth()->check()) {
            $customer['correo_electronico'] = auth()->user()->email;
        }

        $secret = optional(ConfigurationEcommerce::first())->token_private_culqui;
        if (! $secret) {
            return ['success' => false, 'message' => 'El pago con tarjeta no está disponible en este momento.'];
        }

        // The total is always recomputed from database prices; client amounts are ignored.
        try {
            $cart = CartPricing::forStore((array) json_decode((string) $request->items, true));
        } catch (InvalidArgumentException $e) {
            report($e);

            return ['success' => false, 'message' => 'No se pudo procesar el pago.'];

        }

        $result = $checkout->pay(function () use ($customer, $cart, $request) {
            return Order::create([
                'external_id' => Str::uuid()->toString(),
                'customer' => $customer,
                'shipping_address' => 'direccion 1',
                'items' => $cart['lines'],
                'total' => $cart['total'],
                'reference_payment' => 'culqui',
                'status_order_id' => 1,
                'purchase' => json_decode((string) $request->purchase),
            ]);
        }, $cart['total'], $secret, $request->token, $request->email, $request->installments, [
            'antifraud' => ['device_finger_print_id' => $request->input('device_finger_print_id')],
            'authentication_3DS' => (array) $request->input('authentication_3DS', []),
        ]);

        if (! $result['success']) {
            return ['success' => false, 'needs_3ds' => ! empty($result['needs_3ds']), 'message' => $result['message']];
        }

        try {
            $document = new stdClass;
            $document->client = $customer['apellidos_y_nombres_o_razon_social'] ?? $request->email;
            $document->product = 'Compras Ecommerce Facturador Pro';
            $document->total = number_format($cart['total'], 2, '.', '');
            $document->items = $cart['lines'];

            Mail::to($request->email)->send(new CulqiEmail($document));
        } catch (Exception $e) {
            Log::warning('Ecommerce payment confirmation email failed', ['order_id' => $result['order']->id]);
        }

        return ['success' => true, 'order' => $result['order']];
    }
}
