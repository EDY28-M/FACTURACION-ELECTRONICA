<?php

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Tenant\BankAccountController;
use App\Http\Requests\Tenant\BankAccountRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Treasury\Services\TreasuryBalanceService;

class AccountController extends Controller
{
    private $balanceService;
    private $bankAccountController;

    public function __construct(
        TreasuryBalanceService $balanceService,
        BankAccountController $bankAccountController
    ) {
        $this->balanceService = $balanceService;
        $this->bankAccountController = $bankAccountController;
    }

    public function index()
    {
        return view('treasury::accounts.index');
    }

    public function tables()
    {
        return $this->bankAccountController->tables();
    }

    public function records(Request $request)
    {
        $validated = $request->validate([
            'establishment_id' => ['nullable', 'integer'],
            'currency_type_id' => ['nullable', 'string', 'max:3'],
        ]);

        $summary = $this->balanceService->getSummary([
            'establishment_id' => $validated['establishment_id'] ?? null,
            'currency_type_id' => $validated['currency_type_id'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'bank_accounts' => $summary['bank_accounts'],
                'open_cashes' => $summary['open_cashes'],
                'cash_general' => $summary['cash_general'],
                'totals' => $summary['totals'],
            ],
        ]);
    }

    public function record($id)
    {
        return $this->bankAccountController->record($id);
    }

    public function store(BankAccountRequest $request)
    {
        return $this->bankAccountController->store($request);
    }
}
