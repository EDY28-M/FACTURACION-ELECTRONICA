<?php

namespace Modules\Treasury\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Treasury\Http\Requests\LinkRetentionPaymentsRequest;
use Modules\Treasury\Http\Requests\StoreWithholdingRequest;
use Modules\Treasury\Models\TreasuryWithholding;
use Modules\Treasury\Services\RetentionPerceptionService;
use Modules\Treasury\Services\TaxRateRepository;

/**
 * Retention (CRE) and perception (CPE) support. It computes and links; the certificates themselves are
 * emitted by the existing RetentionController / PerceptionController screens.
 */
class WithholdingController extends Controller
{
    private $service;
    private $taxRateRepository;

    public function __construct(RetentionPerceptionService $service, TaxRateRepository $taxRateRepository)
    {
        $this->service = $service;
        $this->taxRateRepository = $taxRateRepository;
    }

    public function tables(): JsonResponse
    {
        $flags = $this->service->getAgentFlags();
        $today = date('Y-m-d');
        $retentionRule = $this->taxRateRepository->getRetentionRule($today);

        return response()->json([
            'is_admin' => $this->isAdmin(auth()->user()),
            'flags' => $flags,
            // Emission options are exposed ONLY when the company is an agent.
            'retention_emit_url' => $flags['is_retention_agent'] ? '/retentions/create' : null,
            'perception_emit_url' => $flags['is_perception_agent'] ? '/perceptions/create' : null,
            'retention_rule' => $flags['is_retention_agent'] ? $retentionRule : null,
            'perception_rates' => $flags['is_perception_agent'] ? $this->taxRateRepository->listPerceptionRates($today) : [],
            'kinds' => [
                ['value' => TreasuryWithholding::KIND_RETENTION_RECEIVED, 'label' => 'Retención recibida (CRE)'],
                ['value' => TreasuryWithholding::KIND_RETENTION_EMITTED, 'label' => 'Retención emitida (CRE)'],
                ['value' => TreasuryWithholding::KIND_PERCEPTION_EMITTED, 'label' => 'Percepción emitida (CPE)'],
            ],
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['nullable', 'in:retention_received,retention_emitted,perception_emitted'],
            'state' => ['nullable', 'in:active,voided'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = auth()->user();
        $isAdmin = $this->isAdmin($user);

        $filters = array_merge($validated, [
            'is_admin' => $isAdmin,
            'user_id' => !$isAdmin ? ($user ? (int) $user->id : null) : null,
            'establishment_id' => !$isAdmin
                ? ($user && !empty($user->establishment_id) ? (int) $user->establishment_id : 0)
                : (!empty($validated['establishment_id']) ? (int) $validated['establishment_id'] : null),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->listWithholdings($filters),
        ]);
    }

    public function retentionOffers(int $purchase): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getRetentionOffers($purchase, $this->scope(auth()->user())),
        ]);
    }

    public function perceptionOffers(Request $request, int $document): JsonResponse
    {
        $validated = $request->validate([
            'rate_code' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->getPerceptionOffers($document, $validated['rate_code'] ?? 'general', $this->scope(auth()->user())),
        ]);
    }

    public function storeReceivedRetention(StoreWithholdingRequest $request, int $document): JsonResponse
    {
        $user = auth()->user();
        $row = $this->service->registerReceivedRetention($document, $request->validated(), (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Comprobante de retención recibido registrado. El saldo neto por cobrar de la factura se actualizó.',
            'data' => ['id' => (int) $row->id],
        ], 201);
    }

    public function linkRetention(StoreWithholdingRequest $request, int $payment): JsonResponse
    {
        $user = auth()->user();
        $row = $this->service->linkIssuedRetention($payment, $request->validated(), (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Comprobante de retención enlazado al pago. El monto retenido quedó pendiente de pago a SUNAT.',
            'data' => ['id' => (int) $row->id],
        ], 201);
    }

    /**
     * Links ONE issued CRE to several payments of the same supplier (their computed retentions must add up to
     * the certificate total).
     */
    public function linkRetentionPayments(LinkRetentionPaymentsRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();
        $rows = $this->service->linkIssuedRetentionPayments($validated['payment_ids'], $validated, (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Comprobante de retención enlazado a ' . count($rows) . ' pago(s). El monto retenido quedó pendiente de pago a SUNAT.',
            'data' => ['ids' => array_map(function ($row) {
                return (int) $row->id;
            }, $rows)],
        ], 201);
    }

    public function linkPerception(StoreWithholdingRequest $request, int $payment): JsonResponse
    {
        $user = auth()->user();
        $row = $this->service->linkIssuedPerception($payment, $request->validated(), (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Comprobante de percepción enlazado al cobro. El monto percibido quedó pendiente de pago a SUNAT.',
            'data' => ['id' => (int) $row->id],
        ], 201);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'void_reason.required' => 'El motivo de anulación es obligatorio.',
            'void_reason.min' => 'El motivo de anulación debe tener al menos 3 caracteres.',
        ]);

        $user = auth()->user();
        $this->service->voidWithholding($id, $validated['void_reason'], (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Registro anulado correctamente.',
        ]);
    }

    /**
     * Links a tax_payment movement to retained/perceived amounts (payment to SUNAT). Admin only.
     */
    public function settle(Request $request): JsonResponse
    {
        if (!$this->isAdmin(auth()->user())) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un administrador puede liquidar montos pagados a SUNAT.',
            ], 403);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'min:1'],
            'movement_id' => ['required', 'integer', 'min:1'],
        ], [
            'ids.required' => 'Seleccione al menos un registro.',
            'movement_id.required' => 'Indique el movimiento de pago de tributos.',
        ]);

        $count = $this->service->settleWithTaxPayment($validated['ids'], (int) $validated['movement_id']);

        return response()->json([
            'success' => true,
            'message' => $count . ' registro(s) marcados como pagados a SUNAT.',
        ]);
    }

    private function scope($user): array
    {
        return [
            'is_admin' => $this->isAdmin($user),
            'user_id' => $user ? (int) $user->id : null,
            'establishment_id' => $user && !empty($user->establishment_id) ? (int) $user->establishment_id : null,
        ];
    }

    private function isAdmin($user): bool
    {
        return $user !== null && ($user->type ?? null) === 'admin';
    }
}
