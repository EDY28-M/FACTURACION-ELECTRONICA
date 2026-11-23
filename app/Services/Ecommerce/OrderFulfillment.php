<?php

namespace App\Services\Ecommerce;

use App\Models\Tenant\Order;
use InvalidArgumentException;

/**
 * Order status changes and comprobante linking.
 *
 * Changing the status (including "despachado") never touches stock: inventory moves only when the
 * order's comprobante is issued, through the normal sale path (DocumentItem::created in
 * InventoryKardexServiceProvider), exactly like any other sale.
 */
class OrderFulfillment
{
    const STATUS_DISPATCHED = 3;
    const STATUS_PAYMENT_VERIFIED = 2;

    /**
     * @param int   $orderId   order to change (locked FOR UPDATE)
     * @param int   $newStatus a valid status_orders id
     * @return array{order:Order}
     * @throws InvalidArgumentException business rule failure (map to 422)
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException unknown order (map to 404)
     */
    public static function changeStatus(int $orderId, int $newStatus): array
    {
        $connection = (new Order)->getConnection();

        return $connection->transaction(function () use ($orderId, $newStatus) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $current = (int) $order->status_order_id;

            if ($newStatus === $current) {
                return ['order' => $order];
            }
            // A dispatched/confirmed order never goes back to an earlier stage.
            if ($current >= self::STATUS_DISPATCHED && $newStatus < $current) {
                throw new InvalidArgumentException('No se puede regresar un pedido despachado o confirmado a un estado anterior.');
            }


            $order->status_order_id = $newStatus;
            $order->save();

            return ['order' => $order];
        });
    }

    /**
     * Links an issued comprobante to the order. Idempotent in the sense that a second call is
     * refused instead of overwriting; the order row is locked for the whole check-and-write.
     *
     * @param callable $documentLookup fn(string $externalId): ?object  document with external_id, number_full, total, state_type_id
     * @throws InvalidArgumentException
     */
    public static function attachDocument(int $orderId, string $externalId, callable $documentLookup): Order
    {
        $connection = (new Order)->getConnection();

        return $connection->transaction(function () use ($orderId, $externalId, $documentLookup) {
            $order = Order::lockForUpdate()->findOrFail($orderId);

            if ($order->document_external_id) {
                throw new InvalidArgumentException('El pedido ya tiene un comprobante asociado ('.$order->number_document.').');
            }

            $document = $documentLookup($externalId);
            if (! $document) {
                throw new InvalidArgumentException('No se encontró el comprobante indicado.');
            }
            if (in_array((string) $document->state_type_id, ['11', '09'], true)) {
                throw new InvalidArgumentException('El comprobante está anulado o rechazado y no se puede asociar al pedido.');
            }
            if (abs((float) $document->total - (float) $order->total) > 0.01) {
                throw new InvalidArgumentException('El total del comprobante no coincide con el total del pedido.');
            }
            if (Order::where('document_external_id', $externalId)->where('id', '!=', $order->id)->exists()) {
                throw new InvalidArgumentException('El comprobante ya está asociado a otro pedido.');
            }

            $order->document_external_id = $externalId;
            $order->number_document = $document->number_full;
            if ((int) $order->status_order_id < self::STATUS_PAYMENT_VERIFIED) {
                $order->status_order_id = self::STATUS_PAYMENT_VERIFIED;
            }
            $order->save();

            return $order;
        });
    }
}
