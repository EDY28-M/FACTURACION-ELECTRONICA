<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Establishment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Http\Requests\CreateMovementFromLineRequest;
use Modules\Treasury\Http\Requests\ImportStatementRequest;
use Modules\Treasury\Http\Requests\PreviewStatementRequest;
use Modules\Treasury\Http\Requests\SaveStatementMappingRequest;
use Modules\Treasury\Http\Requests\StatementLineItemsRequest;
use Modules\Treasury\Models\TreasuryConcept;
use Modules\Treasury\Services\MovementService;
use Modules\Treasury\Services\ReconciliationService;
use Modules\Treasury\Support\StatementParser;

/**
 * Bank reconciliation (Phase 5). Thin controller: validates, scopes by user/establishment and delegates to
 * ReconciliationService, which receives an explicit user id and scope (no auth() inside the service).
 */
class ReconciliationController extends Controller
{
    private $service;
    private $parser;

    public function __construct(ReconciliationService $service, StatementParser $parser)
    {
        $this->service = $service;
        $this->parser = $parser;
    }

    public function index()
    {
        return view('treasury::reconciliation.index');
    }

    public function tables(): JsonResponse
    {
        $user = auth()->user();
        $scope = $this->scope($user);
        $isAdmin = $scope['is_admin'];
        $userEstId = (int) ($scope['establishment_id'] ?? 0);

        $accountsQuery = BankAccount::with('bank')->where('status', 1)->orderBy('id');
        if (!$isAdmin) {
            if ($userEstId <= 0) {
                $accountsQuery->whereRaw('1 = 0');
            } else {
                $accountsQuery->where(function ($q) use ($userEstId) {
                    $q->whereNull('establishment_id')->orWhere('establishment_id', $userEstId);
                });
            }
        }

        $accounts = $accountsQuery->get()->map(function ($row) {
            $bank = $row->bank ? $row->bank->description : 'Banco';

            return [
                'id' => (int) $row->id,
                'bank_id' => (int) $row->bank_id,
                'description' => "{$bank} - {$row->currency_type_id} - {$row->description}",
                'currency_type_id' => $row->currency_type_id,
                'account_type' => $row->account_type ?: 'checking',
                'establishment_id' => $row->establishment_id ? (int) $row->establishment_id : null,
            ];
        })->values();

        $establishmentsQuery = Establishment::query()->orderBy('id');
        if (!$isAdmin) {
            $establishmentsQuery->where('id', $userEstId > 0 ? $userEstId : 0);
        }
        $establishments = $establishmentsQuery->get()->map(function ($row) {
            return ['id' => (int) $row->id, 'description' => $row->description];
        })->values();

        $concepts = TreasuryConcept::query()->where('active', true)->orderBy('type')->orderBy('description')->get()
            ->map(function ($row) {
                return ['id' => (int) $row->id, 'type' => $row->type, 'description' => $row->description];
            })->values();

        $kindLabels = [];
        foreach (array_keys(ReconciliationService::LINE_MOVEMENT_KINDS) as $kind) {
            $kindLabels[] = [
                'value' => $kind,
                'label' => MovementService::KIND_LABELS[$kind] ?? $kind,
                'sign' => ReconciliationService::LINE_MOVEMENT_KINDS[$kind],
            ];
        }

        $dateFormats = [];
        foreach (StatementParser::DATE_FORMATS as $value => $label) {
            $dateFormats[] = ['value' => $value, 'label' => $label];
        }

        return response()->json([
            'accounts' => $accounts,
            'establishments' => $establishments,
            'concepts' => $concepts,
            'movement_kinds' => $kindLabels,
            'date_formats' => $dateFormats,
            'mappings' => $this->service->listMappings($scope),
            'is_admin' => $isAdmin,
            'default_establishment_id' => !$isAdmin && $userEstId > 0 ? $userEstId : null,
            'window_days' => (int) config('treasury.reconciliation.date_window_days', 3),
            'max_file_kb' => (int) config('treasury.reconciliation.max_file_kb', 5120),
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Statements
    // ---------------------------------------------------------------------------------------------------------

    public function statements(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,closed'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->listStatements($validated, $this->scope(auth()->user())),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getStatement($id, $this->scope(auth()->user())),
        ]);
    }

    public function lines(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:unmatched,matched,ignored'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->listLines($id, $validated, $this->scope(auth()->user())),
        ]);
    }

    public function candidates(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'line_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->listCandidates($id, $validated, $this->scope(auth()->user())),
        ]);
    }

    public function summary(int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->summary($id, $this->scope(auth()->user())),
        ]);
    }

    /**
     * Parses the uploaded file with the mapping and returns the first rows, totals and errors. Persists nothing.
     */
    public function preview(PreviewStatementRequest $request): JsonResponse
    {
        $parsed = $this->parseUpload($request->file('file'), (string) $request->input('mapping'));

        return response()->json([
            'success' => true,
            'data' => [
                'lines' => array_slice($parsed['lines'], 0, 15),
                'totals' => $parsed['totals'],
                'errors' => $parsed['errors'],
                'error_count' => $parsed['error_count'],
                'warnings' => $parsed['warnings'],
            ],
        ]);
    }

    public function store(ImportStatementRequest $request): JsonResponse
    {
        $user = auth()->user();
        $scope = $this->scope($user);
        $accountId = (int) $request->input('bank_account_id');

        // Fail fast on access before parsing/storing the file.
        $this->service->assertAccountAccess($accountId, $scope);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $parsed = $this->parseUpload($file, (string) $request->input('mapping'));

        if ($parsed['error_count'] > 0) {
            throw ValidationException::withMessages([
                'file' => array_merge(
                    ['El archivo contiene ' . $parsed['error_count'] . ' fila(s) con errores. Corrija el archivo o el mapeo.'],
                    $parsed['errors']
                ),
            ]);
        }

        $extension = $this->extensionOf($file);
        $folder = (string) config('treasury.reconciliation.storage_folder', 'bank_statements');
        $path = Storage::disk('tenant')->putFileAs($folder, $file, (string) Str::uuid() . '.' . $extension);

        try {
            $statement = $this->service->createStatement($accountId, [
                'period_from' => $request->input('period_from'),
                'period_to' => $request->input('period_to'),
                'opening_balance' => $request->input('opening_balance'),
                'closing_balance' => $request->input('closing_balance'),
                'accept_unbalanced' => $request->boolean('accept_unbalanced'),
            ], $parsed['lines'], $file->getClientOriginalName(), $path ?: null, (int) $user->id, $scope);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('tenant')->delete($path);
            }
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Extracto importado: ' . count($parsed['lines']) . ' movimiento(s).',
            'data' => ['id' => (int) $statement->id],
        ], 201);
    }

    public function discard(int $id): JsonResponse
    {
        $user = auth()->user();
        $path = $this->service->discardStatement($id, (int) $user->id, $this->scope($user));

        if ($path) {
            try {
                Storage::disk('tenant')->delete($path);
            } catch (\Throwable $e) {
                // The statement is already discarded; an orphan file is not worth failing the request.
            }
        }

        return response()->json(['success' => true, 'message' => 'Extracto descartado.']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Matching
    // ---------------------------------------------------------------------------------------------------------

    public function autoMatch(int $id): JsonResponse
    {
        $user = auth()->user();
        $result = $this->service->autoMatch($id, (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => $result['matched'] . ' línea(s) emparejada(s) automáticamente'
                . ($result['ambiguous'] > 0 ? ', ' . $result['ambiguous'] . ' ambigua(s) para revisión manual' : '') . '.',
            'data' => $result,
        ]);
    }

    public function match(StatementLineItemsRequest $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $line = $this->service->matchLine($id, $request->validated()['items'], (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Línea emparejada correctamente.',
            'data' => ['id' => (int) $line->id, 'status' => $line->status],
        ]);
    }

    public function unmatch(int $id): JsonResponse
    {
        $user = auth()->user();
        $line = $this->service->unmatchLine($id, (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Línea desvinculada.',
            'data' => ['id' => (int) $line->id, 'status' => $line->status],
        ]);
    }

    public function ignore(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [
            'reason.required' => 'El motivo para ignorar la línea es obligatorio.',
            'reason.min' => 'El motivo debe tener al menos 3 caracteres.',
            'reason.max' => 'El motivo no puede superar los 255 caracteres.',
        ]);

        $user = auth()->user();
        $line = $this->service->ignoreLine($id, $validated['reason'], (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Línea ignorada.',
            'data' => ['id' => (int) $line->id, 'status' => $line->status],
        ]);
    }

    public function unignore(int $id): JsonResponse
    {
        $user = auth()->user();
        $line = $this->service->unignoreLine($id, (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'La línea vuelve a estar pendiente.',
            'data' => ['id' => (int) $line->id, 'status' => $line->status],
        ]);
    }

    public function createMovement(CreateMovementFromLineRequest $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $scope = $this->scope($user);
        $payload = $request->validated();

        // Default to the establishment of the statement's account (never a spurious mismatch for admins); non-admins
        // are always confined to their own establishment.
        $payload['establishment_id'] = $this->service->resolveLineEstablishmentId(
            $id,
            !empty($payload['establishment_id']) ? (int) $payload['establishment_id'] : null,
            $scope,
            function () {
                return Establishment::orderBy('id')->value('id');
            }
        );

        $result = $this->service->createMovementFromLine($id, $payload, (int) $user->id, $scope);

        return response()->json([
            'success' => true,
            'message' => 'Movimiento registrado y emparejado con la línea.',
            'data' => [
                'line_id' => (int) $result['line']->id,
                'movement_id' => (int) $result['movement']->id,
            ],
        ], 201);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Closing
    // ---------------------------------------------------------------------------------------------------------

    public function close(int $id): JsonResponse
    {
        $user = auth()->user();
        $statement = $this->service->closeStatement($id, (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Conciliación cerrada. La diferencia es ' . number_format((float) ($statement->snapshot['difference'] ?? 0), 2, '.', '') . '.',
            'data' => ['id' => (int) $statement->id, 'status' => $statement->status],
        ]);
    }

    public function reopen(int $id): JsonResponse
    {
        $user = auth()->user();
        $scope = $this->scope($user);

        if (!$scope['is_admin']) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un administrador puede reabrir una conciliación cerrada.',
            ], 403);
        }

        // Re-verify the matches against today's data before the snapshot is discarded (reopening stays allowed:
        // it is how a stale reconciliation gets fixed).
        $staleCount = (int) ($this->service->summary($id, $scope)['stale_count'] ?? 0);
        $statement = $this->service->reopenStatement($id, (int) $user->id, $scope);

        return response()->json([
            'success' => true,
            'message' => 'Conciliación reabierta.',
            'data' => [
                'id' => (int) $statement->id,
                'status' => $statement->status,
                'stale_count' => $staleCount,
            ],
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Mappings
    // ---------------------------------------------------------------------------------------------------------

    public function saveMapping(SaveStatementMappingRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        $config = $this->parser->normalizeConfig($validated['config']);

        $mapping = $this->service->saveMapping($validated, $config, (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Mapeo guardado.',
            'data' => [
                'id' => (int) $mapping->id,
                'name' => $mapping->name,
                'bank_id' => $mapping->bank_id,
                'bank_account_id' => $mapping->bank_account_id,
                'config' => $mapping->config,
            ],
        ]);
    }

    public function deleteMapping(int $id): JsonResponse
    {
        $user = auth()->user();
        $this->service->deleteMapping($id, (int) $user->id, $this->scope($user));

        return response()->json(['success' => true, 'message' => 'Mapeo eliminado.']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    private function parseUpload(UploadedFile $file, string $mappingJson): array
    {
        $decoded = json_decode($mappingJson, true);
        if (!is_array($decoded)) {
            throw ValidationException::withMessages(['mapping' => ['El mapeo de columnas no es válido.']]);
        }

        $config = $this->parser->normalizeConfig($decoded);
        $rows = $this->parser->readRows((string) $file->getRealPath(), $this->extensionOf($file), $config['delimiter']);

        return $this->parser->parse($rows, $config);
    }

    private function extensionOf(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return in_array($extension, ['csv', 'txt', 'xlsx', 'xls'], true) ? $extension : 'csv';
    }

    private function scope($user): array
    {
        return [
            'is_admin' => $user !== null && ($user->type ?? null) === 'admin',
            'user_id' => $user ? (int) $user->id : null,
            'establishment_id' => $user && !empty($user->establishment_id) ? (int) $user->establishment_id : null,
        ];
    }
}
