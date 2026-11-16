<?php

namespace Modules\Sale\Services;

use Exception;

/**
 * Raised when a contract period already has an issued document (idempotency guard).
 */
class ContractAlreadyBilledException extends Exception
{
}
