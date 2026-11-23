<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\Bank;
use App\Models\Tenant\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Treasury\Http\Requests\StorePaymentEvidenceRequest;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\PaymentEvidenceService;

class PaymentEvidenceController extends Controller
{
    private $evidenceService;

    public function __construct(PaymentEvidenceService $evidenceService)
    {
        $this->evidenceService = $evidenceService;
    }

    public function tables()
    {
        $user = auth()->user();
        $isAdmin = $user && ($user->type ?? null) === 'admin';

        $banks = Bank::orderBy('description')->get(['id', 'description']);
        $bankAccountsQuery = BankAccount::with('bank')
            ->where('status', 1)
            ->orderBy('id');

        if (!$isAdmin && $user && !empty($user->establishment_id)) {
            $userEstId = (int) $user->establishment_id;
            $bankAccountsQuery->where(function ($q) use ($userEstId) {
                $q->whereNull('establishment_id')->orWhere('establishment_id', $userEstId);
            });
        }

        $bank_accounts = $bankAccountsQuery
            ->get()
            ->map(function ($row) {
                $bankName = $row->bank ? $row->bank->description : 'Banco';

                return [
                    'id' => (int) $row->id,
                    'bank_id' => (int) $row->bank_id,
                    'currency_type_id' => $row->currency_type_id,
                    'description' => "{$bankName} - {$row->currency_type_id} - {$row->description}",
                ];
            })
            ->values();

        return response()->json([
            'banks' => $banks,
            'bank_accounts' => $bank_accounts,
            'sunat_payment_codes' => BancarizationChecker::sunatPaymentCodeOptions(),
            'bancarization_exceptions' => BancarizationChecker::exceptionOptions(),
        ]);
    }

    public function show($type, $id)
    {
        if ($denied = $this->authorizePayableAlias((string) $type)) {
            return $denied;
        }

        $payable = $this->evidenceService->resolvePayable((string) $type, (int) $id);
        if (!$this->evidenceService->canUserAccessPayable($payable, auth()->user())) {
            return $this->forbiddenResponse();
        }

        $payload = $this->evidenceService->getWithDefaults((string) $type, (int) $id);

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function store(StorePaymentEvidenceRequest $request, $type, $id)
    {
        if ($denied = $this->authorizePayableAlias((string) $type)) {
            return $denied;
        }

        $payable = $this->evidenceService->resolvePayable((string) $type, (int) $id);
        if (!$this->evidenceService->canUserAccessPayable($payable, auth()->user())) {
            return $this->forbiddenResponse();
        }

        $evidence = $this->evidenceService->upsert((string) $type, (int) $id, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Evidencia de bancarización guardada correctamente.',
            'data' => $this->evidenceService->serializeEvidence($evidence),
        ]);
    }

    private function authorizePayableAlias(string $alias): ?JsonResponse
    {
        $requiredModules = $this->evidenceService->requiredModulesForAlias($alias);
        $user = auth()->user();

        if (!$user) {
            return $this->forbiddenResponse();
        }

        $userModules = method_exists($user, 'moduleValues') ? (array) $user->moduleValues() : [];
        if (empty(array_intersect($requiredModules, $userModules))) {
            return $this->forbiddenResponse();
        }

        return null;
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'message' => config('permissions.denied_message', 'No tiene permiso para este módulo.'),
        ], 403);
    }
}
