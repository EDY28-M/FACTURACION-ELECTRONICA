<?php

namespace App\Http\Middleware;

use App\CoreFacturalo\Helpers\Security\ColumnWhitelist;
use Closure;
use Illuminate\Http\Request;

/**
 * Defense-in-depth middleware: rejects any malformed `column` filter parameter
 * (SQL expressions, JSON path arrows `->`, quotes, spaces, semicolons, dots)
 * before it can reach legacy list controllers that pass `$request->column` to
 * Eloquent/QueryBuilder `where($column, ...)`.
 */
class SanitizeFilterColumn
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->has('column')) {
            $column = $request->input('column');
            if ($column !== null && $column !== '' && !ColumnWhitelist::isValidIdentifier($column)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Columna de filtro no válida.',
                ], 422);
            }
        }

        return $next($request);
    }
}
