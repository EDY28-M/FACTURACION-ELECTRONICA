<?php

namespace App\CoreFacturalo\WS\Gre\Exceptions;

/**
 * SUNAT answered with an error status (4xx/5xx), including 422 with errors[].
 */
class GreApiException extends GreException
{
    public function isValidationError()
    {
        return $this->httpStatus === 422 || !empty($this->errors);
    }
}
