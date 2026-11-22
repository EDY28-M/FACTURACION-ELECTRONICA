<?php

namespace App\Exceptions;

use Error;
use ErrorException;
use Exception;
use InvalidArgumentException;
use PDOException;
use Throwable;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /** @var array<int, bool> */
    private array $reportedIds = [];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable $exception
     * @return void
     * @throws \Throwable
     */
    public function report(Throwable $exception)
    {
        $this->reportedIds[spl_object_id($exception)] = true;
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof AuthenticationException) {
            if ($this->isFrontend($request)) {
                return redirect()->guest('login');
            }
            return $this->errorResponse('No se encuentra autenticado', 401, $exception);
        }
        if($exception instanceof AuthorizationException) {
            return $this->errorResponse('No posee permisos para ejecutar esta acción', 403, $exception);
        }
        if ($exception instanceof ValidationException) {
            return $this->convertValidationExceptionToResponse($exception, $request);
        }
        if ($exception instanceof ModelNotFoundException) {
            return $this->isFrontend($request)
                ? parent::render($request, $exception)
                : $this->errorResponse('Registro no encontrado', 404, $exception);
        }
        if($exception instanceof NotFoundHttpException) {
            return $this->errorResponse('No se encontró la URL especificada', 404, $exception);
        }
        if($exception instanceof MethodNotAllowedHttpException) {
            return $this->errorResponse('El método especificado en la petición no es válido', 405, $exception);
        }

        if (preg_match('/InventoryTrait.php/', $exception->getFile())) {
            return $this->errorResponse($exception->getMessage(), 405, $exception);
        }
        // if ($exception->getStatusCode() == 403 && !$this->isFrontend($request)) {
        //     return $this->errorResponse('Acceso denegado: Su cuenta está inactiva, comuníquese con el administrador', 403, $exception);
        // }
        if($exception instanceof HttpException) {
            return $this->errorResponse('', $exception->getStatusCode(), $exception);
        }

        if(!$this->isFrontend($request)) {
            return $this->errorResponse('', 500, $exception);
        }

        return parent::render($request, $exception);
    }

    protected function convertValidationExceptionToResponse(ValidationException $e, $request)
    {
        $errors = $e->validator->errors()->getMessages();

        if ($this->isFrontend($request)) {
            return $request->ajax()? response()->json($errors, 422):redirect()
                ->back()
                ->withInput($request->input())
                ->withErrors($errors);
        }

        return $this->errorResponse($errors, 422, $e);
    }

    private function isFrontend(Request $request)
    {
        return $request->acceptsHtml()
            && $request->route()
            && collect($request->route()->middleware())->contains('web');
    }

    private function errorResponse($message, $code, Throwable $exception)
    {
        $code = ($code === '' || !is_int($code) || $code < 100 || $code > 599) ? 500 : $code;
        $debug = (bool) config('app.debug');

        if ($code >= 500 && !isset($this->reportedIds[spl_object_id($exception)])) {
            $this->report($exception);
        }

        if ($code >= 500 && !$debug && !$this->isUserFacing($exception)) {
            $message = 'Error interno del servidor';
        } elseif ($message === '') {
            $message = $exception->getMessage();
        }

        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($debug) {
            $payload['file'] = $exception->getFile();
            $payload['line'] = $exception->getLine();
        }

        return response()->json($payload, $code);
    }

    /**
     * Whether the exception message was written on purpose for the end user.
     *
     * The application reports business errors (SUNAT rejections, send deadline,
     * validation of domain rules) with `new Exception('<message>')`, custom
     * App\ / Modules\ exceptions, InvalidArgumentException and HttpException
     * (abort()), so their messages are safe to show. Database, PHP engine and
     * vendor/library errors are never user-facing: their messages may leak SQL,
     * paths or credentials, so they are masked in production.
     */
    private function isUserFacing(Throwable $exception): bool
    {
        if ($exception instanceof QueryException
            || $exception instanceof PDOException
            || $exception instanceof ErrorException
            || $exception instanceof Error) {
            return false;
        }

        $class = get_class($exception);

        return $class === Exception::class
            || $exception instanceof InvalidArgumentException
            || $exception instanceof HttpException
            || str_starts_with($class, 'App\\')
            || str_starts_with($class, 'Modules\\');
    }
}
