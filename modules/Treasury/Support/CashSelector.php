<?php

namespace Modules\Treasury\Support;

use App\Models\Tenant\Cash;

/**
 * Cash shift options for the Treasury selectors (cash book report, cash count).
 *
 * Returns every OPEN shift plus the most recent closed ones (config treasury.reports.cash_selector_limit, default
 * 200), optionally narrowed by `search` (reference number, owner name or id) and an opening-date range, so a tenant
 * with years of shifts never serializes the whole table into a dropdown.
 *
 * Non-admins only get their own shifts, and only when the owner belongs to their establishment (or has none).
 */
final class CashSelector
{
    public const DEFAULT_LIMIT = 200;

    /**
     * @param array{search?: ?string, date_start?: ?string, date_end?: ?string} $filters
     */
    public static function options(bool $isAdmin, int $userEstId, int $userId, array $filters = []): array
    {
        if (!$isAdmin && ($userEstId <= 0 || $userId <= 0)) {
            return [];
        }

        $configured = (int) config('treasury.reports.cash_selector_limit', self::DEFAULT_LIMIT);
        $limit = $configured > 0 ? $configured : self::DEFAULT_LIMIT;

        $base = function () use ($isAdmin, $userEstId, $userId, $filters) {
            $query = Cash::query()
                ->leftJoin('users', 'users.id', '=', 'cash.user_id')
                ->select('cash.*');

            if (!$isAdmin) {
                $query->where('cash.user_id', $userId)
                    ->where(function ($q) use ($userEstId) {
                        $q->whereNull('users.establishment_id')->orWhere('users.establishment_id', $userEstId);
                    });
            }

            $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
            if ($search !== '') {
                $search = mb_substr($search, 0, 60);
                $like = '%' . addcslashes($search, '\\%_') . '%';
                $query->where(function ($q) use ($like, $search) {
                    $q->where('cash.reference_number', 'like', $like)
                        ->orWhere('users.name', 'like', $like);
                    if (ctype_digit($search)) {
                        $q->orWhere('cash.id', (int) $search);
                    }
                });
            }
            if (!empty($filters['date_start'])) {
                $query->where('cash.date_opening', '>=', (string) $filters['date_start']);
            }
            if (!empty($filters['date_end'])) {
                $query->where('cash.date_opening', '<=', (string) $filters['date_end']);
            }

            return $query->with('user');
        };

        $open = $base()->where('cash.state', true)
            ->orderBy('cash.date_opening', 'desc')->orderBy('cash.id', 'desc')
            ->get();
        $closed = $base()->where('cash.state', false)
            ->orderBy('cash.date_opening', 'desc')->orderBy('cash.id', 'desc')
            ->limit($limit)
            ->get();

        return $open->concat($closed)->map(function ($row) {
            $owner = $row->user ? $row->user->name : ('Usuario #' . $row->user_id);
            $ref = $row->reference_number ? " ({$row->reference_number})" : '';
            $stateLabel = $row->state ? 'Abierta' : 'Cerrada';

            return [
                'id' => (int) $row->id,
                'description' => "Caja POS{$ref} - {$owner} [{$stateLabel}]",
                'state' => (bool) $row->state,
                'user_id' => (int) $row->user_id,
                'establishment_id' => ($row->user && $row->user->establishment_id) ? (int) $row->user->establishment_id : null,
            ];
        })->values()->all();
    }

    /**
     * Whitelisted selector filters from a request-like input array.
     */
    public static function filtersFrom(?array $input): array
    {
        $input = $input ?? [];
        $filters = [];
        if (isset($input['search']) && is_string($input['search'])) {
            $filters['search'] = $input['search'];
        }
        foreach (['date_start', 'date_end'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input[$key])) {
                $filters[$key] = $input[$key];
            }
        }

        return $filters;
    }
}
