<?php
namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Expense\Http\Resources\ExpensePaymentCollection;
use Modules\Expense\Http\Requests\ExpensePaymentRequest;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseMethodType;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Traits\FinanceTrait; 
use Modules\Finance\Traits\FilePaymentTrait; 
use Illuminate\Support\Facades\DB;

class ExpensePaymentController extends Controller
{

    use FinanceTrait, FilePaymentTrait;

    public function records($expense_id)
    {
        Expense::whereTypeUser()->findOrFail($expense_id);
        $records = ExpensePayment::where('expense_id', $expense_id)->get();

        return new ExpensePaymentCollection($records);
    }

    public function tables()
    {
        return [
            'expense_method_types' => ExpenseMethodType::all(),
            'payment_destinations' => $this->getPaymentDestinations()
        ];
    }

    public function Expense($expense_id)
    {
        $expense = Expense::whereTypeUser()->findOrFail($expense_id);

        $total_paid = collect($expense->payments)->sum('payment');
        $total = $expense->total;
        $total_difference = round($total - $total_paid, 2);

        return [
            'number_full' => $expense->number_full,
            'total_paid' => $total_paid,
            'total' => $total,
            'total_difference' => $total_difference
        ];

    }


    public function store(ExpensePaymentRequest $request)
    {
        $id = $request->input('id');

        return DB::connection('tenant')->transaction(function () use ($id, $request) {

            if ($id) {
                $record = ExpensePayment::lockForUpdate()->findOrFail($id);
                $expense = Expense::whereTypeUser()->lockForUpdate()->findOrFail($record->expense_id);
            } else {
                $expense = Expense::whereTypeUser()
                    ->lockForUpdate()
                    ->findOrFail($request->input('expense_id'));
                $record = new ExpensePayment();
                $record->expense_id = $expense->id;
            }

            if ((string) $expense->state_type_id === '11') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede registrar pagos en un gasto anulado.',
                ], 422);
            }

            $record->fill($request->except(['id', 'expense_id']));
            $record->expense_id = $expense->id;

            if ($request->expense_method_type_id == 1) {
                $request['payment_destination_id'] = 'cash';
            }

            $record->save();
            $this->createGlobalPayment($record, $request->all());
            $this->saveFiles($record, $request, 'expenses');

            return [
                'success' => true,
                'message' => ($id) ? 'Pago editado con éxito' : 'Pago registrado con éxito'
            ];
        });
    }

    public function destroy($id)
    {
        return DB::connection('tenant')->transaction(function () use ($id) {
            $item = ExpensePayment::lockForUpdate()->findOrFail($id);
            $expense = Expense::whereTypeUser()->lockForUpdate()->findOrFail($item->expense_id);

            if ((string) $expense->state_type_id === '11') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pueden eliminar pagos de un gasto anulado.',
                ], 422);
            }

            $item->delete();

            return [
                'success' => true,
                'message' => 'Pago eliminado con éxito'
            ];
        });
    }
 


}
