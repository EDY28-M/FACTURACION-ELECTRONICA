<?php
namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tenant\ExchangeRateCollection;
use App\Models\Tenant\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ExchangeRateController extends Controller
{
    public function records()
    {
        $records = ExchangeRate::orderBy('date', 'desc')->get();

        return new ExchangeRateCollection($records);
    }

    public function store(Request $request)
    {
        $rows = $request->except('_token');
        if (empty($rows) || !is_array($rows)) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de tipo de cambio inválidos.',
            ], 422);
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cada registro de tipo de cambio debe ser un objeto.',
                ], 422);
            }
        }

        $validator = Validator::make($rows, [
            '*.date' => 'required|date',
            '*.date_original' => 'nullable|date',
            '*.purchase' => 'nullable|numeric',
            '*.purchase_original' => 'nullable|numeric',
            '*.sale' => 'required|numeric',
            '*.sale_original' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::connection('tenant')->transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $purchase = $row['purchase'] ?? $row['sale'];
                ExchangeRate::updateOrCreate(
                    ['date' => $row['date']],
                    [
                        'date_original' => $row['date_original'] ?? $row['date'],
                        'purchase' => $purchase,
                        'purchase_original' => $row['purchase_original'] ?? $purchase,
                        'sale' => $row['sale'],
                        'sale_original' => $row['sale_original'] ?? $row['sale'],
                    ]
                );
            }
        });

        return [
            'success' => true,
            'message' => 'Tipos de cambio registrados con éxito'
        ];
    }
}