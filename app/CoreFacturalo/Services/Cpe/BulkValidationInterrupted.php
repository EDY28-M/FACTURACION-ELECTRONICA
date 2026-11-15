<?php

namespace App\CoreFacturalo\Services\Cpe;

use App\CoreFacturalo\WS\Gre\Exceptions\GreException;
use RuntimeException;

/**
 * A bulk validation stopped midway because of a SUNAT/connection/credential error. Carries the original
 * exception and the progress already stored, so callers can report partial counts with the error.
 */
class BulkValidationInterrupted extends RuntimeException
{
    /** @var GreException */
    private $cause;

    /** @var array processed, by_status, next_after_id, remaining */
    private $partial;

    public function __construct(GreException $cause, array $partial)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
        $this->cause = $cause;
        $this->partial = $partial;
    }

    public function getCause()
    {
        return $this->cause;
    }

    public function getPartial()
    {
        return $this->partial;
    }
}
