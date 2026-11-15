<?php

namespace App\CoreFacturalo\Services\Sire;

use App\Models\Tenant\SireRequest;

/**
 * Persistence used by SireReconciliationService (swappable in unit tests).
 */
interface SireRequestStore
{
    /**
     * Runs $fn while holding an exclusive lock on the period's requests, so the "is there an open request?"
     * check and the creation of a new one cannot interleave between two concurrent clicks.
     *
     * @return mixed whatever $fn returns
     */
    public function atomic(string $period, callable $fn);

    /** Requests of the period that are still open (requested/processing/ready), newest first. */
    public function openRecords(string $period): array;

    public function save(SireRequest $record): SireRequest;
}
