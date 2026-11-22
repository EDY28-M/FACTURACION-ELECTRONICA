<?php
namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Purchase\Http\Resources\PurchasePaymentCollection;
use Modules\Purchase\Http\Requests\PurchasePaymentRequest;
use App\Models\Tenant\PaymentMethodType;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\Purchase;
use Modules\Finance\Traits\FinanceTrait; 
use Modules\Finance\Traits\FilePaymentTrait; 
use Illuminate\Support\Facades\DB;

class PurchasePaymentController extends Controller
{
    use FinanceTrait, FilePaymentTrait;

    public function records($purchase_id)
    {
        Purchase::whereTypeUser()->findOrFail($purchase_id);
        $records = PurchasePayment::where('purchase_id', $purchase_id)->get();

        return new PurchasePaymentCollection($records);
    }

    public function tables()
    {
        return [
            'payment_method_types' => PaymentMethodType::all(),
            'payment_destinations' => $this->getPaymentDestinations()
        ];
    }

    public function purchase($purchase_id)
    {
        $purchase = Purchase::find($purchase_id);

        $total_paid = collect($purchase->payments)->sum('payment');
        $total = $purchase->total;
        $total_difference = round($total - $total_paid, 2);

        return [
            'number_full' => $purchase->number_full,
            'total_paid' => $total_paid,
            'total' => $total,
            'total_difference' => $total_difference
        ];

    }


    public function store(PurchasePaymentRequest $request)
    {
        $id = $request->input('id');

        return DB::connection('tenant')->transaction(function () use ($id, $request) {

            if ($id) {
                $record = PurchasePayment::lockForUpdate()->findOrFail($id);
                $purchase = Purchase::whereTypeUser()->lockForUpdate()->findOrFail($record->purchase_id);
            } else {
                $purchase = Purchase::whereTypeUser()
                    ->lockForUpdate()
                    ->findOrFail($request->input('purchase_id'));
                $record = new PurchasePayment();
                $record->purchase_id = $purchase->id;
            }

            if ((string) $purchase->state_type_id === '11') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pueden registrar pagos de una compra anulada.',
                ], 422);
            }

            $record->fill($request->except(['id', 'purchase_id']));
            $record->purchase_id = $purchase->id;
            $record->save();

            $this->createGlobalPayment($record, $request->all());
            $this->saveFiles($record, $request, 'purchases');

            return [
                'success' => true,
                'message' => ($id) ? 'Pago editado con éxito' : 'Pago registrado con éxito',
            ];
        });
    }

    public function destroy($id)
    {
        return DB::connection('tenant')->transaction(function () use ($id) {
            $item = PurchasePayment::lockForUpdate()->findOrFail($id);
            $purchase = Purchase::whereTypeUser()->lockForUpdate()->findOrFail($item->purchase_id);

            if ((string) $purchase->state_type_id === '11') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pueden eliminar pagos de una compra anulada.',
                ], 422);
            }

            $item->delete();

            return [
                'success' => true,
                'message' => 'Pago eliminado con éxito',
            ];
        });
    }
 


}
