<?php

namespace App\Services\Payments;

/**
 * Storefront card checkout: the order exists BEFORE the charge, is marked paid only
 * after a verified approval and is cancelled (soft deleted) when the charge fails.
 */
class EcommerceCheckout
{
    const STATUS_PAID = 2;

    private $charger;

    public function __construct(CulqiCharger $charger)
    {
        $this->charger = $charger;
    }

    /**
     * @param callable $createOrder fn(): object order exposing status_order_id, reference_payment, save(), delete()
     * @return array{success:bool,message:?string,order:object}
     */
    public function pay(callable $createOrder, float $total, string $secret, string $token, string $email, $installments = null): array
    {
        $order = $createOrder();
        $cents = (int) round($total * 100);

        $result = $cents > 0
            ? $this->charger->charge($secret, $cents, $email, 'Compras Ecommerce Facturador Pro', $token, $installments, ['order_id' => $order->id ?? null])
            : ['paid' => false, 'unknown' => false, 'id' => null, 'message' => 'El monto de la compra no es válido.'];

        if (! $result['paid']) {
            if ($result['unknown']) {
                // The card may have been charged: keep the order pending for manual reconciliation.
                $order->reference_payment = 'culqui:pendiente';
                $order->save();
            } else {
                $order->reference_payment = 'culqui:rechazado';
                $order->save();
                $order->delete();
            }

            return ['success' => false, 'message' => $result['message'], 'order' => $order];
        }

        $order->status_order_id = self::STATUS_PAID;
        $order->reference_payment = 'culqui:'.$result['id'];
        $order->save();

        return ['success' => true, 'message' => null, 'order' => $order];
    }
}
