<?php

namespace Modules\Treasury\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Treasury\Models\TreasuryHoliday;
use Modules\Treasury\Services\InstrumentService;

/**
 * Holidays (feriados) used for the SPOT deposit deadline. Any Treasury user can read the list; only an
 * administrator can add or delete entries (new decrees are published every year).
 */
class HolidayController extends Controller
{
    public function index()
    {
        return view('treasury::holidays.index');
    }

    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $query = TreasuryHoliday::query()->orderBy('date');
        if (!empty($validated['year'])) {
            $year = (int) $validated['year'];
            $query->where('date', '>=', sprintf('%04d-01-01', $year))->where('date', '<=', sprintf('%04d-12-31', $year));
        }

        $years = DB::connection('tenant')->table('treasury_holidays')->orderBy('date')->pluck('date')
            ->map(function ($date) {
                return (int) substr((string) $date, 0, 4);
            })->unique()->values()->all();

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(function (TreasuryHoliday $row) {
                return [
                    'id' => (int) $row->id,
                    'date' => substr((string) $row->getRawOriginal('date'), 0, 10),
                    'name' => (string) $row->name,
                    'legal_reference' => $row->legal_reference,
                    'source_url' => $row->source_url,
                    'scope' => (string) $row->scope,
                    'is_business_day_override' => (bool) $row->is_business_day_override,
                ];
            })->values()->all(),
            'years' => $years,
            'is_admin' => $this->isAdmin(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('tenant.treasury_holidays', 'date')],
            'name' => ['required', 'string', 'max:120'],
            'legal_reference' => ['nullable', 'string', 'max:160'],
            'source_url' => ['nullable', 'url', 'max:255'],
            'is_business_day_override' => ['nullable', 'boolean'],
        ], [
            'date.required' => 'Indique la fecha del feriado.',
            'date.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'date.unique' => 'Ya existe un feriado registrado en esa fecha.',
            'name.required' => 'Indique el nombre del feriado.',
            'source_url.url' => 'El enlace de la fuente no es válido.',
        ]);

        $holiday = TreasuryHoliday::create([
            'date' => $validated['date'],
            'name' => trim($validated['name']),
            'legal_reference' => $validated['legal_reference'] ?? null,
            'source_url' => $validated['source_url'] ?? null,
            'scope' => 'national',
            'is_business_day_override' => (bool) ($validated['is_business_day_override'] ?? false),
        ]);

        $this->refreshInstrumentDeadlines();

        return response()->json(['success' => true, 'message' => 'Feriado registrado.', 'id' => (int) $holiday->id]);
    }

    public function destroy($id): JsonResponse
    {
        if (!$this->isAdmin()) {
            return $this->forbidden();
        }

        $holiday = TreasuryHoliday::findOrFail((int) $id);
        $holiday->delete();

        $this->refreshInstrumentDeadlines();

        return response()->json(['success' => true, 'message' => 'Feriado eliminado.']);
    }

    /**
     * Protest deadlines of open bills depend on the holidays: refresh them now that the list changed. The holiday
     * change itself must never fail because of this derived data (it is also recomputed when listing / alerting).
     */
    private function refreshInstrumentDeadlines(): void
    {
        try {
            app(InstrumentService::class)->refreshOpenBillDeadlines();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function isAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->type ?? null) === 'admin';
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Solo un administrador puede modificar los feriados.',
        ], 403);
    }
}
