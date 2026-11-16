<?php

namespace App\Services\Payments;

/**
 * A tenant paying a scheduled account payment to the platform.
 * The amount always comes from the stored record, never from the request.
 */
class PlatformPayment
{
    private $charger;

    public function __construct(CulqiCharger $charger)
    {
        $this->charger = $charger;
    }

    /**
     * @param object $payment record exposing payment, state, date_of_payment_real, reference_payment, save()
     * @return array{success:bool,message:string,charge_id:?string}
     */
    public function pay($payment, string $secret, string $token, string $email, $installments = null): array
    {
        if ((int) $payment->state !== 0) {
            return ['success' => false, 'message' => 'Este pago ya fue registrado.', 'charge_id' => null];
        }

        // A previous attempt with unknown outcome: never charge again until an admin clears the marker.
        if ($payment->reference_payment === 'culqui:pendiente') {
            return ['success' => false, 'message' => 'Este pago está pendiente de verificación. Contacte a soporte.', 'charge_id' => null];
        }

        $cents = (int) round(((float) $payment->payment) * 100);
        if ($cents <= 0) {
            return ['success' => false, 'message' => 'El monto del pago no es válido.', 'charge_id' => null];
        }

        $result = $this->charger->charge($secret, $cents, $email, 'Pago programado facturador', $token, $installments, ['account_payment_id' => $payment->id ?? null]);
        if (! $result['paid']) {
            if ($result['unknown']) {
                $payment->reference_payment = 'culqui:pendiente';
                $payment->save();
            }

            return ['success' => false, 'message' => $result['message'], 'charge_id' => null];
        }

        $payment->state = 1;
        $payment->date_of_payment_real = date('Y-m-d');
        $payment->reference_payment = 'culqi:'.$result['id'];
        $payment->save();

        return ['success' => true, 'message' => 'Pago efectuado correctamente', 'charge_id' => $result['id']];
    }
}
