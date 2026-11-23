<?php

namespace Modules\Finance\Traits; 

use App\Models\Tenant\Cash;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Company;
use Carbon\Carbon;
use Modules\Expense\Models\ExpensePayment;
use App\Models\Tenant\{
    DocumentPayment,
    SaleNotePayment,
    PurchasePayment
};
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\ContractPayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Pos\Models\CashTransaction;
use Modules\Sale\Models\TechnicalServicePayment;
use Modules\Treasury\Services\TreasuryBalanceService;


trait FinanceTrait
{ 

    public function getPaymentDestinations(){
        
        $bank_accounts = self::getBankAccounts();
        $cash = $this->getCash();

        // dd($cash);
        if($cash){
            return collect($bank_accounts)->push($cash);
        }

        return $bank_accounts;

    }


    private static function getBankAccounts(){

        return BankAccount::get()->transform(function($row) {
            return [
                'id' => $row->id,
                'cash_id' => null,
                'description' => "{$row->bank->description} - {$row->currency_type_id} - {$row->description}",
            ];
        });

    }


    public function getCash(){

        $cash =  Cash::where([['user_id',auth()->user()->id],['state',true]])->first();

        if($cash){
            
            return [
                'id' => 'cash',
                'cash_id' => $cash->id,
                'description' => ($cash->reference_number) ? "CAJA GENERAL - {$cash->reference_number}" : "CAJA GENERAL",
            ];

        }
        // else{
            
        //     $cash_create = Cash::create([
        //                             'user_id' => auth()->user()->id,
        //                             'date_opening' => date('Y-m-d'),
        //                             'time_opening' => date('H:i:s'),
        //                             'date_closed' => null,
        //                             'time_closed' => null,
        //                             'beginning_balance' => 0,
        //                             'final_balance' => 0,
        //                             'income' => 0,
        //                             'state' => true,
        //                             'reference_number' => null
        //                         ]);

        //     return [
        //         'id' => 'cash',
        //         'cash_id' => $cash_create->id,
        //         'description' => "CAJA GENERAL"
        //     ];

        // }

        return null;

    }

    public function createGlobalPayment($model, $row){

        $destination = $this->getDestinationRecord($row); 
        $company = Company::active();

        // Editing a payment must move its global payment, not append another row.
        \Modules\Finance\Models\GlobalPayment::where('payment_id', $model->getKey())
            ->where('payment_type', get_class($model))
            ->delete();

        $model->global_payment()->create([
            'user_id' => auth()->id(),
            'soap_type_id' => $company->soap_type_id,
            'destination_id' => $destination['destination_id'],
            'destination_type' => $destination['destination_type'],
        ]);

    }

    public function getDestinationRecord($row){
        
        if($row['payment_destination_id'] === 'cash'){

            $destination_id = $this->getCash()['cash_id'];
            $destination_type = Cash::class;

        }else{

            $destination_id = $row['payment_destination_id'];
            $destination_type = BankAccount::class;

        }

        return [
            'destination_id' => $destination_id,
            'destination_type' => $destination_type,
        ];
    }

    
    public function deleteAllPayments($payments){

        foreach ($payments as $payment) {
            \Modules\Finance\Models\GlobalPayment::where('payment_id', $payment->getKey())
                ->where('payment_type', get_class($payment))
                ->delete();

            $payment->delete();
        }

    }

    public function getCollectionPaymentTypes(){

        return [
            ['id'=> DocumentPayment::class, 'description' => 'COMPROBANTES (CPE)'],
            ['id'=> SaleNotePayment::class, 'description' => 'NOTAS DE VENTA'],
            ['id'=> PurchasePayment::class, 'description' => 'COMPRAS'],
            ['id'=> ExpensePayment::class, 'description' => 'GASTOS'],
            ['id'=> QuotationPayment::class, 'description' => 'COTIZACIÓN'],
            ['id'=> ContractPayment::class, 'description' => 'CONTRATO'],
            ['id'=> IncomePayment::class, 'description' => 'INGRESO'],
            // ['id'=> CashTransaction::class, 'description' => 'CAJA CHICA POS'],
            ['id'=> TechnicalServicePayment::class, 'description' => 'SERVICIO TÉCNICO'],
        ];
    }

    public function getCollectionDestinationTypes(){

        return [
            ['id'=> Cash::class, 'description' => 'CAJA GENERAL'],
            ['id'=> BankAccount::class, 'description' => 'CUENTA BANCARIA'],
        ];
    }

    public function getDatesOfPeriod($request){

        $period = $request['period'];
        $date_start = $request['date_start'];
        $date_end = $request['date_end'];
        $month_start = $request['month_start'];
        $month_end = $request['month_end'];
        
        $d_start = null;
        $d_end = null;

        switch ($period) {
            case 'month':
                $d_start = Carbon::parse($month_start.'-01')->format('Y-m-d');
                $d_end = Carbon::parse($month_start.'-01')->endOfMonth()->format('Y-m-d');
                break;
            case 'between_months':
                $d_start = Carbon::parse($month_start.'-01')->format('Y-m-d');
                $d_end = Carbon::parse($month_end.'-01')->endOfMonth()->format('Y-m-d');
                break;
            case 'date':
                $d_start = $date_start;
                $d_end = $date_start;
                break;
            case 'between_dates':
                $d_start = $date_start;
                $d_end = $date_end;
                break;
        }

        return [
            'd_start' => $d_start,
            'd_end' => $d_end
        ];
    }

    
    protected function treasuryBalanceService(): TreasuryBalanceService
    {
        return new TreasuryBalanceService();
    }

    public function getBalanceByCash($cash, ?string $dateStart = null, ?string $dateEnd = null){
        return $this->treasuryBalanceService()->getBalanceByCash($cash, null, $dateStart, $dateEnd);
    }


    public function getSumPaymentCashPos($record, $model)
    {
        return $this->treasuryBalanceService()->getSumPaymentCashPos($record, $model);
    }
    
    
    public function getBalanceByBankAcounts($bank_accounts, ?string $dateStart = null, ?string $dateEnd = null){
        return $this->treasuryBalanceService()->getBalanceByBankAccounts($bank_accounts, null, $dateStart, $dateEnd);
    }

    public function getSumPayment($record, $model)
    {
        return $this->treasuryBalanceService()->getSumPayment($record, $model);
    }
    

    public function getTotalCreditNotes($record)
    {
        return $this->treasuryBalanceService()->getTotalCreditNotes($record);
    }
    

    public function calculateTotalCurrencyType($record, $payment)
    {
        return $this->treasuryBalanceService()->calculateTotalCurrencyType($record, $payment);
    }

    
    public function getRecordsByPaymentMethodTypes($payment_method_types)
    {
        
        $records = $payment_method_types->map(function($row){

            $document_payment = $this->getSumByPMT($row->document_payments, true);
            $sale_note_payment = $this->getSumByPMT($row->sale_note_payments);
            $purchase_payment = $this->getSumByPMT($row->purchase_payments); 
            $quotation_payment = $this->getSumByPMT($row->quotation_payments); 
            $contract_payment = $this->getSumByPMT($row->contract_payments); 
            // $contract_payment = 0; //$this->getSumByPMT($row->contract_payments); 
            $cash_transaction = $row->cash_transactions->sum('payment'); 
            $income_payment = $this->getSumByPMT($row->income_payments) + $cash_transaction; 
            $technical_service_payment = $this->getSumByPMT($row->technical_service_payments); 


            return [

                'id' => $row->id,
                'description' => $row->description, 
                'expense_payment' => '-',
                'sale_note_payment' => number_format($sale_note_payment,2, ".", ""),
                'document_payment' => number_format($document_payment,2, ".", ""),
                'purchase_payment' => number_format($purchase_payment,2, ".", ""),
                'quotation_payment' => number_format($quotation_payment,2, ".", ""),
                'contract_payment' => number_format($contract_payment,2, ".", ""),
                'income_payment' => number_format($income_payment,2, ".", ""),
                'technical_service_payment' => number_format($technical_service_payment,2, ".", ""),
                
            ];

        }); 

        return $records;
    }

    
    public function getRecordsByExpenseMethodTypes($expense_method_types)
    {
        
        $records = $expense_method_types->map(function($row){

            // dd($row->expense_payments);
            $expense_payment = $this->getSumByPMT($row->expense_payments); 

            return [

                'id' => $row->id,
                'description' => $row->description, 
                'expense_payment' => number_format($expense_payment,2, ".", ""),
                'sale_note_payment' => '-',
                'document_payment' => '-',
                'quotation_payment' => '-',
                'contract_payment' => '-',
                'income_payment' => '-',
                'purchase_payment' => '-',
                'technical_service_payment' => '-',
                
            ];

        }); 

        return $records;
    }

    public function getSumByPMT($records, $include_credit_notes = false)
    {
        $total = 0;
        $creditNotesByDocument = [];

        foreach ($records as $row) {
            $associated = $row->associated_record_payment ?? null;
            if (!$associated) {
                continue;
            }

            $total += $this->calculateTotalCurrencyType($associated, $row->payment);

            if ($include_credit_notes && $row instanceof \App\Models\Tenant\DocumentPayment) {
                $key = $associated->getKey();
                if (!array_key_exists($key, $creditNotesByDocument)) {
                    $creditNotesByDocument[$key] = $this->getTotalCreditNotes($associated);
                }
            }
        }

        return $total - array_sum($creditNotesByDocument);
    }

    public function getTotalsPaymentMethodType($records_by_pmt, $records_by_emt)
    {
        
        $t_documents = 0;
        $t_sale_notes = 0;
        $t_quotations = 0;
        $t_contracts = 0;
        $t_purchases = 0;
        $t_expenses = 0;
        $t_income = 0;
        $t_technical_services = 0;

        foreach ($records_by_pmt as $value) {

            $t_documents += $value['document_payment'];
            $t_sale_notes += $value['sale_note_payment'];
            $t_quotations += $value['quotation_payment'];
            $t_contracts += $value['contract_payment'];
            $t_purchases += $value['purchase_payment'];
            $t_income += $value['income_payment'];
            $t_technical_services += $value['technical_service_payment'];

        }

        foreach ($records_by_emt as $value) {

            $t_expenses += $value['expense_payment'];
            
        }

        return [
            't_documents' => number_format($t_documents,2, ".", ""),
            't_sale_notes' => number_format($t_sale_notes,2, ".", ""),
            't_quotations' => number_format($t_quotations,2, ".", ""),
            't_contracts' => number_format($t_contracts,2, ".", ""),
            't_purchases' => number_format($t_purchases,2, ".", ""),
            't_expenses' => number_format($t_expenses,2, ".", ""),
            't_income' => number_format($t_income,2, ".", ""),
            't_technical_services' => number_format($t_technical_services,2, ".", ""),
        ];

    }



    //cash transaction
    
    public function getCashTransaction($user_id){

        $cash =  Cash::where([['user_id', $user_id],['state',true]])->first();

        if($cash){
            
            return [
                'id' => 'cash',
                'cash_id' => $cash->id,
                'description' => ($cash->reference_number) ? "CAJA GENERAL - {$cash->reference_number}" : "CAJA GENERAL",
            ];

        } 

        return null;

    }

    public function createGlobalPaymentTransaction($model, $row){

        $destination = $this->getDestinationRecordTransaction($row); 
        $company = Company::active();

        $model->global_payment()->create([
            'user_id' => auth()->id(),
            'soap_type_id' => $company->soap_type_id,
            'destination_id' => $destination['destination_id'],
            'destination_type' => $destination['destination_type'],
        ]);

    }

    public function getDestinationRecordTransaction($row){
        
        if($row['payment_destination_id'] === 'cash'){

            $destination_id = $this->getCashTransaction($row['user_id'])['cash_id'];
            $destination_type = Cash::class;

        }else{

            $destination_id = $row['payment_destination_id'];
            $destination_type = BankAccount::class;

        }

        return [
            'destination_id' => $destination_id,
            'destination_type' => $destination_type,
        ];
    }


}
