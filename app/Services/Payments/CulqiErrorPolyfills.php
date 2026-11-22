<?php

namespace Culqi\Error;

if (! class_exists(CulqiException::class, false)) {
    class CulqiException extends \Exception
    {
    }
}

if (! class_exists(UnableToConnect::class, false)) {
    class UnableToConnect extends CulqiException
    {
    }
}

if (! class_exists(UnhandledError::class, false)) {
    class UnhandledError extends CulqiException
    {
    }
}

if (! class_exists(AuthenticationError::class, false)) {
    class AuthenticationError extends CulqiException
    {
    }
}

if (! class_exists(InvalidApiKey::class, false)) {
    class InvalidApiKey extends CulqiException
    {
    }
}

if (! class_exists(NotFound::class, false)) {
    class NotFound extends CulqiException
    {
    }
}

if (! class_exists(MethodNotAllowed::class, false)) {
    class MethodNotAllowed extends CulqiException
    {
    }
}

if (! class_exists(InputValidationError::class, false)) {
    class InputValidationError extends CulqiException
    {
    }
}
