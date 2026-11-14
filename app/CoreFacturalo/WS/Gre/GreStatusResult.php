<?php

namespace App\CoreFacturalo\WS\Gre;

/**
 * Normalized answer of the GRE ticket status endpoint.
 *
 * codRespuesta: "98" processing, "0" accepted, "99" rejected.
 */
class GreStatusResult
{
    const PROCESSING = 'processing';
    const ACCEPTED = 'accepted';
    const REJECTED = 'rejected';

    /** @var string */
    public $status;

    /** @var string */
    public $code;

    /** @var bool */
    public $cdrGenerated = false;

    /** @var string|null binary content of the CDR zip */
    public $cdrZip;

    /** @var string|null */
    public $errorCode;

    /** @var string|null */
    public $errorMessage;

    /** @var array raw response */
    public $raw = [];

    public function isProcessing()
    {
        return $this->status === self::PROCESSING;
    }

    public function isAccepted()
    {
        return $this->status === self::ACCEPTED;
    }

    public function isRejected()
    {
        return $this->status === self::REJECTED;
    }
}
