<?php
namespace App\Http\Controllers\Tenant;

use App\Imports\ItemsImport;
use App\Models\Tenant\Catalogs\AffectationIgvType;
use App\Models\Tenant\Catalogs\AttributeType;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Catalogs\SystemIscType;
use App\Models\Tenant\Catalogs\UnitType;
use App\Models\Tenant\User;
use App\Models\Tenant\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\CashRequest;
use App\Http\Resources\Tenant\CashCollection;
use App\Http\Resources\Tenant\CashResource;
use App\Models\Tenant\Cash;
use App\Models\Tenant\CashDocument;
use App\Models\Tenant\Document;
use App\Models\Tenant\SaleNote;
use Exception;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use App\Models\Tenant\DocumentItem;
use App\Models\Tenant\PaymentMethodType;
use Modules\Pos\Models\CashTransaction;
use Modules\Finance\Traits\FinanceTrait;
use Modules\Treasury\Services\CashTreasuryIntegrationService;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant\SaleNoteItem;
use App\Exports\CashProductExport;


class CashController extends Controller
{

    use FinanceTrait;

    public function index()
    {
        return view('tenant.cash.index');
    }

    public function columns()
    {
        return [
            'income' => 'Ingresos',
            // 'expense' => 'Egresos',
        ];
    }

    public function records(Request $request)
    {
        $allowedColumns = array_keys($this->columns());
        $column = in_array($request->column, $allowedColumns, true) ? $request->column : 'income';

        $records = Cash::where($column, 'like', "%{$request->value}%")
                        ->whereTypeUser()
                        ->orderBy('date_opening', 'DESC');


        return new CashCollection($records->paginate(config('tenant.items_per_page')));
    }

    public function create()
    {
        return view('tenant.items.form');
    }

    public function tables()
    {
        $user = auth()->user();
        $type = $user->type;
        $users = array();

        switch($type)
        {
            case 'admin':
                $users = User::where('type', 'seller')->get();
                $users->push($user);
                break;
            case 'seller':
                $users = User::where('id', $user->id)->get();
                break;
        }

        return compact('users', 'user');
    }

    public function opening_cash()
    {

        $cash = Cash::where([['user_id', auth()->user()->id],['state', true]])->first();

        return compact('cash');
    }

    public function opening_cash_check($user_id)
    {
        $cash = Cash::where([['user_id', $user_id],['state', true]])->first();
        return compact('cash');
    }


    public function record($id)
    {
        $query = Cash::query();
        if (auth()->user()->type !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        $record = new CashResource($query->findOrFail($id));

        return $record;
    }

    public function store(CashRequest $request) {

        $id = $request->input('id');

        return DB::connection('tenant')->transaction(function () use ($id, $request) {

            if ($id) {
                $cashQuery = Cash::where('id', $id);
                if (auth()->user()->type !== 'admin') {
                    $cashQuery->where('user_id', auth()->id());
                }
                $cash = $cashQuery->lockForUpdate()->firstOrFail();

                if (!$cash->state) {
                    return [
                        'success' => false,
                        'message' => 'No se puede editar una caja cerrada.',
                    ];
                }
            } else {
                // Serialize concurrent openings for the same user.
                User::whereKey(auth()->id())->lockForUpdate()->first();

                if (Cash::where('user_id', auth()->id())->where('state', true)->exists()) {
                    return [
                        'success' => false,
                        'message' => 'Ya tiene una caja aperturada.',
                    ];
                }

                $cash = new Cash();
                $cash->user_id = auth()->id();
                $cash->state = true;
                $cash->date_opening = date('Y-m-d');
                $cash->time_opening = date('H:i:s');
            }

            $cash->beginning_balance = $request->input('beginning_balance');
            if ($request->filled('reference_number')) {
                $cash->reference_number = $request->input('reference_number');
            }
            $cash->save();

            $this->createCashTransaction($cash, $request);

            return [
                'success' => true,
                'message' => ($id) ? 'Caja actualizada con éxito' : 'Caja aperturada con éxito',
            ];
        });
    }

    public function createCashTransaction($cash, $request){

        $this->destroyCashTransaction($cash);

        $data = [
            'date' => date('Y-m-d'),
            'description' => 'Saldo inicial',
            'payment_method_type_id' => '01',
            'payment' => $request->beginning_balance,
            'payment_destination_id' => 'cash',
            'user_id' => $cash->user_id,
        ];

        $cash_transaction = $cash->cash_transaction()->create($data);

        $this->createGlobalPaymentTransaction($cash_transaction, $data);

    }


    public function close($id) {
        return DB::connection('tenant')->transaction(function () use ($id) {
            $cashQuery = Cash::with([
                'cash_documents.sale_note',
                'cash_documents.document',
                'cash_documents.expense_payment.expense',
            ]);

            if (auth()->user()->type !== 'admin') {
                $cashQuery->where('user_id', auth()->id());
            }

            $cash = $cashQuery->lockForUpdate()->findOrFail($id);

            if (!$cash->state) {
                return response()->json([
                    'success' => false,
                    'message' => 'La caja ya se encuentra cerrada.',
                ], 422);
            }

            $cash->date_closed = date('Y-m-d');
            $cash->time_closed = date('H:i:s');

            $final_balance = 0;

            foreach ($cash->cash_documents as $cash_document) {
                if ($cash_document->sale_note) {
                    if (in_array($cash_document->sale_note->state_type_id, ['01', '03', '05', '07', '13'])) {
                        $final_balance += ($cash_document->sale_note->currency_type_id == 'PEN')
                            ? $cash_document->sale_note->total
                            : ($cash_document->sale_note->total * $cash_document->sale_note->exchange_rate_sale);
                    }
                } elseif ($cash_document->document) {
                    if (in_array($cash_document->document->state_type_id, ['01', '03', '05', '07', '13'])) {
                        $final_balance += ($cash_document->document->currency_type_id == 'PEN')
                            ? $cash_document->document->total
                            : ($cash_document->document->total * $cash_document->document->exchange_rate_sale);
                    }
                } elseif ($cash_document->expense_payment && $cash_document->expense_payment->expense) {
                    if ($cash_document->expense_payment->expense->state_type_id == '05') {
                        $final_balance -= ($cash_document->expense_payment->expense->currency_type_id == 'PEN')
                            ? $cash_document->expense_payment->payment
                            : ($cash_document->expense_payment->payment * $cash_document->expense_payment->expense->exchange_rate_sale);
                    }
                }
            }

            $final_balance += $this->sumActiveTreasuryMovementsForCash($cash, $cash->date_closed);

            $cash->final_balance = round($final_balance + $cash->beginning_balance, 2);
            $cash->income = round($final_balance, 2);
            $cash->state = false;
            $cash->save();

            return [
                'success' => true,
                'message' => 'Caja cerrada con éxito',
            ];
        });
    }

    protected function sumActiveTreasuryMovementsForCash(Cash $cash, string $dateClosed): float
    {
        $service = app()->bound(CashTreasuryIntegrationService::class)
            ? app(CashTreasuryIntegrationService::class)
            : new CashTreasuryIntegrationService();

        return $service->sumNetForCash($cash, $dateClosed);
    }


    public function cash_document(Request $request) {
        $data = $request->validate([
            'document_id' => 'nullable|integer',
            'sale_note_id' => 'nullable|integer',
            'expense_payment_id' => 'nullable|integer',
        ]);

        if (!$data['document_id'] && !$data['sale_note_id'] && !$data['expense_payment_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Debe indicar un documento para asociar a la caja.',
            ], 422);
        }

        return DB::connection('tenant')->transaction(function () use ($data) {
            $cash = Cash::where('user_id', auth()->id())
                ->where('state', true)
                ->lockForUpdate()
                ->first();

            if (!$cash) {
                return response()->json([
                    'success' => false,
                    'message' => 'No existe una caja abierta para el usuario.',
                ], 422);
            }

            if (!empty($data['document_id'])) {
                $document = Document::whereTypeUser()->find($data['document_id']);
                if (!$document || (string) $document->state_type_id === '11') {
                    return response()->json([
                        'success' => false,
                        'message' => 'El documento no existe o está anulado.',
                    ], 422);
                }
            }

            if (!empty($data['sale_note_id'])) {
                $saleNote = SaleNote::whereTypeUser()->find($data['sale_note_id']);
                if (!$saleNote || (string) $saleNote->state_type_id === '11') {
                    return response()->json([
                        'success' => false,
                        'message' => 'La nota de venta no existe o está anulada.',
                    ], 422);
                }
            }

            $cash->cash_documents()->create($data);

            return [
                'success' => true,
                'message' => 'Venta con éxito',
            ];
        });
    }


    public function destroy($id)
    {

        $data = DB::connection('tenant')->transaction(function () use ($id) {

            $cashQuery = Cash::query();
            if (auth()->user()->type !== 'admin') {
                $cashQuery->where('user_id', auth()->id());
            }
            $cash = $cashQuery->lockForUpdate()->findOrFail($id);

            if($cash->global_destination()->where('payment_type', '!=', CashTransaction::class)->count() > 0){
                return [
                    'success' => false,
                    'message' => 'No puede eliminar la caja, tiene transacciones relacionadas'
                ];
            }

            $this->destroyCashTransaction($cash);
            $cash->delete();

            return [
                'success' => true,
                'message' => 'Caja eliminada con éxito'
            ];

        });

        return $data;

    }


    public function destroyCashTransaction($cash){

        $ini_cash_transaction = $cash->cash_transaction;

        if($ini_cash_transaction){
            CashTransaction::find($ini_cash_transaction->id)->delete();
        }

    }


    public function report($cash) {

        $query = Cash::query();
        if (auth()->user()->type !== 'admin') {
            $query->where('user_id', auth()->id());
        }
        $cash = $query->findOrFail($cash);
        $company = Company::first();

        $methods_payment = collect(PaymentMethodType::all())->transform(function($row){
            return (object)[
                'id' => $row->id,
                'name' => $row->description,
                'sum' => 0
            ];
        });

        $treasuryService = app()->bound(CashTreasuryIntegrationService::class)
            ? app(CashTreasuryIntegrationService::class)
            : new CashTreasuryIntegrationService();
        $treasury_summary = $treasuryService->summarizeForCash($cash);

        set_time_limit(0);

        $pdf = PDF::loadView('tenant.cash.report_pdf', compact("cash", "company", "methods_payment", "treasury_summary"));

        $filename = "Reporte_POS - {$cash->user->name} - {$cash->date_opening} {$cash->time_opening}";

        return $pdf->stream($filename.'.pdf');
    }

    public function report_general()
    {
        $cashes = Cash::select('id')->whereDate('date_opening', date('Y-m-d'))->pluck('id');
        $cash_documents =  CashDocument::whereIn('cash_id', $cashes)->get();

        $company = Company::first();
        set_time_limit(0);

        $pdf = PDF::loadView('tenant.cash.report_general_pdf', compact("cash_documents", "company"));
        $filename = "Reporte_POS";
        return $pdf->download($filename.'.pdf');

    }

    public function report_products($id)
    {

        $data = $this->getDataReport($id);
        $pdf = PDF::loadView('tenant.cash.report_product_pdf', $data);

        $filename = "Reporte_POS_PRODUCTOS - {$data['cash']->user->name} - {$data['cash']->date_opening} {$data['cash']->time_opening}";

        return $pdf->stream($filename.'.pdf');

    }



    public function report_products_excel($id)
    {

        $data = $this->getDataReport($id);
        $filename = "Reporte_POS_PRODUCTOS - {$data['cash']->user->name} - {$data['cash']->date_opening} {$data['cash']->time_opening}";

        return (new CashProductExport)
                ->documents($data['documents'])
                ->company($data['company'])
                ->cash($data['cash'])
                ->download($filename.'.xlsx');

    }


    public function getDataReport($id){

        $cash = Cash::findOrFail($id);
        $company = Company::first();
        $cash_documents =  CashDocument::select('document_id')->where('cash_id', $cash->id)->get();

        $source = DocumentItem::with('document')->whereIn('document_id', $cash_documents)->get();

        $documents = collect($source)->transform(function($row){
            return [
                'id' => $row->id,
                'number_full' => $row->document->number_full,
                'description' => $row->item->description,
                'quantity' => $row->quantity,
            ];
        });

        $documents = $documents->merge($this->getSaleNotesReportProducts($cash));

        return compact("cash", "company", "documents");

    }


    public function getSaleNotesReportProducts($cash){

        $cd_sale_notes =  CashDocument::select('sale_note_id')->where('cash_id', $cash->id)->get();

        $sale_note_items = SaleNoteItem::with('sale_note')->whereIn('sale_note_id', $cd_sale_notes)->get();

        return collect($sale_note_items)->transform(function($row){
            return [
                'id' => $row->id,
                'number_full' => $row->sale_note->number_full,
                'description' => $row->item->description,
                'quantity' => $row->quantity,
            ];
        });

    }

}
