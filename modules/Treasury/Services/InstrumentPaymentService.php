<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\Purchase;
use App\Models\Tenant\PurchasePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Traits\FinanceTrait;
use Modules\Treasury\Models\TreasuryInstrument;
use Modules\Treasury\Support\ClosedReconciliationGuard;
use Modules\Treasury\Support\Money;

/**
 * Settles a cheque / bill that is LINKED to a document (receivable) or purchase (payable) through the EXISTING legacy
 * payment flow (D2/D4: Treasury never duplicates document payments, it invokes them).
 *
 * It mirrors DocumentPaymentController::store / PurchasePaymentController::store: an Eloquent DocumentPayment /
 * PurchasePayment is saved (the model observers recompute `total_canceled`) and its GlobalPayment is created by
 * FinanceTrait::createGlobalPayment() with the bank account as destination. Undoing it deletes the payment model
 * (the existing `deleting` listeners cascade the global payment, files and evidence), after asking
 * ClosedReconciliationGuard so a payment matched in a closed statement is never removed.
 *
 * Lock order inside InstrumentService::transition(): instrument (already locked) -> source row -> payment.
 * Never calls auth(): the acting user is passed explicitly.
 */
class InstrumentPaymentService
{
    use FinanceTrait;

    /** @return array{0: string, 1: string, 2: string} [table, payments table, fk column] of a source class */
    private function sourceMeta(string $sourceClass): array
    {
        if ($sourceClass === Document::class) {
            return ['documents', 'document_payments', 'document_id'];
        }
        if ($sourceClass === Purchase::class) {
            return ['purchases', 'purchase_payments', 'purchase_id'];
        }

        throw ValidationException::withMessages(['source_id' => ['El documento vinculado no es válido.']]);
    }

    /**
     * Source row (optionally locked) with its paid and pending amounts.
     *
     * @return array{row: object, paid: float, pending: float}
     */
    public function loadSource(string $sourceClass, int $sourceId, bool $lock = false): array
    {
        [$table, $payTable, $fk] = $this->sourceMeta($sourceClass);

        $query = DB::connection('tenant')->table($table)->where('id', $sourceId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if (!$row) {
            throw ValidationException::withMessages([
                'source_id' => [$sourceClass === Document::class ? 'El comprobante vinculado no existe.' : 'La compra vinculada no existe.'],
            ]);
        }

        $paid = Money::round((float) DB::connection('tenant')->table($payTable)->where($fk, $sourceId)->sum('payment'));
        $pending = Money::round((float) $row->total - $paid);

        return ['row' => $row, 'paid' => $paid, 'pending' => $pending];
    }

    /**
     * Rules a linked instrument must satisfy at link time and again at collection: the source is accepted (not
     * voided), still has a pending balance, the amount does not exceed it, the currency is the same and the person
     * (when the instrument has one) is the customer / supplier of the source.
     */
    public function assertCollectable(string $sourceClass, int $sourceId, float $amount, string $currency, ?int $personId, bool $lock = false): void
    {
        $info = $this->loadSource($sourceClass, $sourceId, $lock);
        $row = $info['row'];
        $isDocument = $sourceClass === Document::class;

        if (!in_array((string) $row->state_type_id, TreasuryBalanceService::ACCEPTED_STATE_TYPES, true)) {
            throw ValidationException::withMessages([
                'source_id' => [$isDocument ? 'El comprobante seleccionado no existe o no está vigente.' : 'La compra seleccionada no existe o no está vigente.'],
            ]);
        }

        if (strtoupper((string) $row->currency_type_id) !== strtoupper($currency)) {
            throw ValidationException::withMessages([
                'source_id' => ['La moneda del documento vinculado (' . $row->currency_type_id . ') debe coincidir con la del cheque o la letra.'],
            ]);
        }

        if ($info['pending'] <= 0) {
            throw ValidationException::withMessages([
                'source_id' => [$isDocument ? 'El comprobante vinculado ya está cancelado.' : 'La compra vinculada ya está cancelada.'],
            ]);
        }

        if (Money::round($amount) > $info['pending'] + 0.0001) {
            throw ValidationException::withMessages([
                'amount' => ['El monto (' . Money::format($amount) . ') supera el saldo pendiente del documento vinculado (' . Money::format($info['pending']) . ').'],
            ]);
        }

        $counterparty = $isDocument ? ($row->customer_id ?? null) : ($row->supplier_id ?? null);
        if ($personId !== null && $personId > 0 && (int) $counterparty !== $personId) {
            throw ValidationException::withMessages([
                'person_id' => [$isDocument
                    ? 'El cliente del cheque o la letra no coincide con el cliente del comprobante vinculado.'
                    : 'El proveedor del cheque o la letra no coincide con el proveedor de la compra vinculada.'],
            ]);
        }
    }

    /**
     * Registers the collection / payment of a linked instrument through the legacy payment flow.
     *
     * @return DocumentPayment|PurchasePayment
     */
    public function registerPayment(TreasuryInstrument $instrument, int $bankAccountId, string $date, int $userId)
    {
        $sourceClass = (string) $instrument->source_type;
        $sourceId = (int) $instrument->source_id;

        // Lock the source (same as the controllers do) and re-check everything: the balance may have changed since linking.
        $this->assertCollectable(
            $sourceClass,
            $sourceId,
            (float) $instrument->amount,
            (string) $instrument->currency_type_id,
            $instrument->person_id ? (int) $instrument->person_id : null,
            true
        );

        $methodId = $this->paymentMethodTypeId($instrument);
        $reference = mb_substr(($instrument->isCheque() ? 'Cheque ' : 'Letra ') . $instrument->number, 0, 255);

        if ($sourceClass === Document::class) {
            $payment = new DocumentPayment();
            $payment->document_id = $sourceId;
        } else {
            $payment = new PurchasePayment();
            $payment->purchase_id = $sourceId;
        }

        $payment->fill([
            'date_of_payment' => $date,
            'payment_method_type_id' => $methodId,
            'has_card' => false,
            'reference' => $reference,
            'payment' => Money::round((float) $instrument->amount),
        ]);
        $payment->save();

        // Same call the legacy controllers make; the bank account is the destination.
        $this->createGlobalPayment($payment, ['payment_destination_id' => $bankAccountId], $userId);

        return $payment;
    }

    /**
     * Deletes the legacy payment of a linked instrument (bounce / correction). The closed-reconciliation guard is
     * consulted FIRST so its message surfaces untouched; the model deletion then reuses the legacy cascade.
     */
    public function removePayment(TreasuryInstrument $instrument): void
    {
        if (empty($instrument->payment_type) || empty($instrument->payment_id)) {
            return;
        }

        $class = (string) $instrument->payment_type;
        if (!in_array($class, [DocumentPayment::class, PurchasePayment::class], true)) {
            return;
        }

        // Lock the source row first (same order as registerPayment), then the payment.
        if (!empty($instrument->source_type) && !empty($instrument->source_id)) {
            [$table] = $this->sourceMeta((string) $instrument->source_type);
            DB::connection('tenant')->table($table)->where('id', (int) $instrument->source_id)->lockForUpdate()->first();
        }

        $payment = $class::query()->whereKey((int) $instrument->payment_id)->lockForUpdate()->first();
        if (!$payment) {
            // Already deleted from the legacy screen: nothing left to reverse.
            return;
        }

        ClosedReconciliationGuard::assertPaymentDeletable($payment);

        $payment->delete();
    }

    private function paymentMethodTypeId(TreasuryInstrument $instrument): string
    {
        $key = $instrument->isCheque() ? 'cheque' : 'bill';
        $id = (string) config('treasury.instruments.payment_method_type.' . $key, '');

        if ($id === '' || !DB::connection('tenant')->table('payment_method_types')->where('id', $id)->exists()) {
            throw ValidationException::withMessages([
                'payment_method_type' => ['No se encontró el método de pago configurado para cheques y letras (treasury.instruments.payment_method_type). Revise la configuración.'],
            ]);
        }

        return $id;
    }
}
