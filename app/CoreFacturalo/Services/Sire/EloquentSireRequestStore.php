<?php

namespace App\CoreFacturalo\Services\Sire;

use App\CoreFacturalo\WS\Sire\SireClient;
use App\Models\Tenant\SireRequest;

class EloquentSireRequestStore implements SireRequestStore
{
    public function atomic(string $period, callable $fn)
    {
        return SireRequest::query()->getConnection()->transaction(function () use ($period, $fn) {
            // Locks the period's rows (gap lock when empty) until commit.
            SireRequest::where('book', SireClient::BOOK_RVIE)->where('period', $period)->lockForUpdate()->get(['id']);

            return $fn();
        });
    }

    public function openRecords(string $period): array
    {
        return SireRequest::where('book', SireClient::BOOK_RVIE)->where('period', $period)
            ->whereIn('status', [SireRequest::STATUS_REQUESTED, SireRequest::STATUS_PROCESSING, SireRequest::STATUS_READY])
            ->orderByDesc('id')->get()->all();
    }

    public function save(SireRequest $record): SireRequest
    {
        $record->save();

        return $record;
    }
}
