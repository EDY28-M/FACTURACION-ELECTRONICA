<?php
namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\BankAccountRequest;
use App\Http\Resources\Tenant\BankAccountCollection;
use App\Http\Resources\Tenant\BankAccountResource;
use App\Models\Tenant\Bank;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Establishment;
use Exception;
use Modules\Treasury\Services\TreasuryBalanceService;

class BankAccountController extends Controller
{
    public function index()
    {
        return view('tenant.bank_accounts.index');
    }

    public function records()
    {
        $records = BankAccount::all();

        return new BankAccountCollection($records);
    }

    public function create()
    {
        return view('tenant.bank_accounts.index');
    }

    public function tables()
    {
        $banks = Bank::all();
        $currency_types = CurrencyType::whereActive()->get();
        $establishments = Establishment::all()->map(function ($row) {
            return [
                'id' => $row->id,
                'description' => $row->description,
            ];
        });
        $account_types = collect(TreasuryBalanceService::ACCOUNT_TYPE_LABELS)->map(function ($label, $value) {
            return [
                'id' => $value,
                'description' => $label,
            ];
        })->values();

        // Referential PLE Tabla 3 suggestions (unconfirmed); the stored code is what the PLE export uses.
        $financial_entity_codes = collect(config('treasury.ple.financial_entity_codes', []))->map(function ($label, $code) {
            return [
                'id' => (string) $code,
                'description' => $code . ' - ' . $label,
            ];
        })->values();

        return compact('banks', 'currency_types', 'establishments', 'account_types', 'financial_entity_codes');
    }


    public function record($id)
    {
        $record = new BankAccountResource(BankAccount::findOrFail($id));

        return $record;
    }

    public function store(BankAccountRequest $request)
    {
        $id = $request->input('id');
        $bank_account = BankAccount::firstOrNew(['id' => $id]);
        $data = $request->validated();

        if (!array_key_exists('account_type', $data) || $data['account_type'] === null || $data['account_type'] === '') {
            if (!$bank_account->exists) {
                $data['account_type'] = 'checking';
            } else {
                unset($data['account_type']);
            }
        }

        if (!array_key_exists('itf_exempt', $data) || $data['itf_exempt'] === null) {
            if (!$bank_account->exists) {
                $effectiveType = $data['account_type'] ?? 'checking';
                $data['itf_exempt'] = ($effectiveType === 'detraction_bn');
            } else {
                unset($data['itf_exempt']);
            }
        }

        $bank_account->fill($data);
        $bank_account->save();

        return [
            'success' => true,
            'message' => ($id)?'Cuenta bancaria editada con éxito':'Cuenta bancaria registrada con éxito'
        ];
    }

    public function destroy($id)
    {
        try {
            
            $bank_account = BankAccount::findOrFail($id);
            $bank_account->delete(); 

            return [
                'success' => true,
                'message' => 'Cuenta bancaria eliminada con éxito'
            ];

        } catch (Exception $e) {

            return ($e->getCode() == '23000') ? ['success' => false,'message' => 'La Cuenta bancaria esta siendo usada por otros registros, no puede eliminar'] : ['success' => false,'message' => 'Error inesperado, no se pudo eliminar la cuenta bancaria'];

        } 
    }
}