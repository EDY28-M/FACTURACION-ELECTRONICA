<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Finance\Models\GlobalPayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Expense\Models\ExpensePayment;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;
use App\Models\Tenant\Cash;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Company;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Modules\Finance\Traits\FinanceTrait; 
use Modules\Finance\Http\Resources\GlobalPaymentCollection;
use Modules\Finance\Exports\BalanceExport;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use App\Models\Tenant\Establishment;
use Carbon\Carbon;

class BalanceController extends Controller
{ 

    use FinanceTrait;

    public function index(){

        return view('finance::balance.index');
    }


    public function filter(){

        $payment_types = [];
        $destination_types = [];

        return compact('payment_types', 'destination_types');
    }


    public function records(Request $request)
    {

        // dd($request->all());
        $records = $this->getRecords($request->all());
        
        return $records;

    }

    public function getRecords($request){

        $data_of_period = $this->getDatesOfPeriod($request); 

        $params = (object)[
            'date_start' => $data_of_period['d_start'],
            'date_end' => $data_of_period['d_end'],
        ];
        
        $paymentEagerLoad = [
            'payment' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    DocumentPayment::class => [
                        'associated_record_payment' => function ($q) {
                            $q->without(['user', 'soap_type', 'state_type', 'document_type', 'currency_type', 'group', 'items', 'invoice', 'note', 'payments'])
                              ->select('id', 'currency_type_id', 'exchange_rate_sale')
                              ->with(['affected_documents' => function ($nq) {
                                  $nq->without(['affected_document', 'note_credit_type', 'note_debit_type'])
                                     ->with(['document' => function ($dq) {
                                         $dq->without(['user', 'soap_type', 'state_type', 'document_type', 'currency_type', 'group', 'items', 'invoice', 'note', 'payments'])
                                            ->select('id', 'state_type_id', 'currency_type_id', 'exchange_rate_sale', 'total');
                                     }]);
                              }]);
                        },
                    ],
                    SaleNotePayment::class => [
                        'associated_record_payment' => function ($q) {
                            $q->without(['user', 'soap_type', 'state_type', 'currency_type', 'items', 'payments'])
                              ->select('id', 'currency_type_id', 'exchange_rate_sale');
                        },
                    ],
                    PurchasePayment::class => [
                        'associated_record_payment' => function ($q) {
                            $q->without(['user', 'soap_type', 'state_type', 'document_type', 'currency_type', 'group', 'items', 'purchase_payments'])
                              ->select('id', 'currency_type_id', 'exchange_rate_sale');
                        },
                    ],
                    QuotationPayment::class => [
                        'associated_record_payment' => function ($q) {
                            $q->without(['user', 'soap_type', 'state_type', 'currency_type', 'items', 'payments'])
                              ->select('id', 'currency_type_id', 'exchange_rate_sale');
                        },
                    ],
                    ContractPayment::class => [
                        'associated_record_payment' => function ($q) {
                            $q->without(['user', 'soap_type', 'state_type', 'currency_type', 'items', 'payments'])
                              ->select('id', 'currency_type_id', 'exchange_rate_sale');
                        },
                    ],
                    ExpensePayment::class => ['associated_record_payment'],
                    IncomePayment::class => ['associated_record_payment'],
                    TechnicalServicePayment::class => ['associated_record_payment'],
                ]);
            },
        ];

        $bank_accounts = BankAccount::with([
            'bank',
            'global_destination' => function ($query) use ($params, $paymentEagerLoad) {
                $query->whereFilterPaymentType($params)->with($paymentEagerLoad);
            },
        ])->get();

        $all_cash = GlobalPayment::whereFilterPaymentType($params)
                                    ->with($paymentEagerLoad)
                                    ->whereDestinationType(Cash::class)
                                    ->get();


        $balance_by_bank_acounts = $this->getBalanceByBankAcounts($bank_accounts, $params->date_start, $params->date_end);
        $balance_by_cash = $this->getBalanceByCash($all_cash, $params->date_start, $params->date_end);

        return $balance_by_bank_acounts->push($balance_by_cash);
        
    }

    
    public function pdf(Request $request) {

        $company = Company::first();
        $establishment = ($request->establishment_id) ? Establishment::findOrFail($request->establishment_id) : auth()->user()->establishment;
        $records = $this->getRecords($request->all());

        $pdf = PDF::loadView('finance::balance.report_pdf', compact("records", "company", "establishment"));

        $filename = 'Balance_'.date('YmdHis');

        return $pdf->download($filename.'.pdf');
    }


    public function excel(Request $request) {

        $company = Company::first();
        $establishment = ($request->establishment_id) ? Establishment::findOrFail($request->establishment_id) : auth()->user()->establishment;
        $records = $this->getRecords($request->all());

        return (new BalanceExport)
                ->records($records)
                ->company($company)
                ->establishment($establishment)
                ->download('Balance_'.Carbon::now().'.xlsx');

    }

}
