<?php

namespace App\CoreFacturalo\WS\Gre\Exceptions;

use Exception;

/**
 * Base exception for the SUNAT GRE REST API. Carries the SUNAT error payload
 * ({cod, msg, exc, errors[]}) so the UI can show it.
 */
class GreException extends Exception
{
    /** @var int|null */
    protected $httpStatus;

    /** @var string|null */
    protected $sunatCode;

    /** @var string|null */
    protected $sunatMessage;

    /** @var array */
    protected $errors = [];

    public function __construct($message = '', $httpStatus = null, $sunatCode = null, $sunatMessage = null, array $errors = [], $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
        $this->sunatCode = $sunatCode;
        $this->sunatMessage = $sunatMessage;
        $this->errors = $errors;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getSunatCode()
    {
        return $this->sunatCode;
    }

    public function getSunatMessage()
    {
        return $this->sunatMessage;
    }

    /**
     * @return array list of ['codError' => ..., 'desError' => ...]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Human readable (Spanish) summary including every SUNAT error line.
     */
    public function getDetailedMessage()
    {
        $lines = [$this->getMessage()];
        foreach ($this->errors as $error) {
            $code = $error['codError'] ?? '';
            $desc = $error['desError'] ?? '';
            $lines[] = trim("{$code} {$desc}");
        }

        return implode(' | ', array_filter($lines));
    }
}
