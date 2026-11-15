<?php

namespace Modules\Report\Http\Controllers;

use App\CoreFacturalo\Services\Sire\RvieReplacementBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class ReportSireRvieController extends Controller
{
    public function index()
    {
        return view('report::sire.rvie');
    }

    public function preview(Request $request)
    {
        try {
            $result = $this->generate($request);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'filename' => $result['zip_filename'],
            'rows_count' => $result['rows_count'],
            'totals' => array_merge($result['totals'], ['by_type' => array_values($result['totals']['by_type'])]),
            'warnings' => $result['warnings'],
            'errors' => $result['errors'],
            'demo' => $result['demo'],
        ]);
    }

    public function download(Request $request)
    {
        $txtPath = tempnam(sys_get_temp_dir(), 'rvietxt');

        try {
            $result = $this->generate($request, $txtPath);

            if ($result['errors_count'] > 0) {
                @unlink($txtPath);

                return response()->json([
                    'success' => false,
                    'message' => 'Hay comprobantes que impiden generar el archivo. Corrigelos e intenta nuevamente.',
                    'errors' => $result['errors'],
                ], 422);
            }

            if ($result['demo'] && !filter_var($request->query('force_demo'), FILTER_VALIDATE_BOOLEAN)) {
                @unlink($txtPath);

                return response()->json([
                    'success' => false,
                    'message' => 'La empresa esta en ambiente de pruebas. Confirma que deseas descargar el archivo de todas formas.',
                    'errors' => [],
                ], 422);
            }

            $zipPath = RvieReplacementBuilder::zipFile($result['filename'], $txtPath);
        } catch (InvalidArgumentException|RuntimeException $e) {
            @unlink($txtPath);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        @unlink($txtPath);

        return response()->download($zipPath, $result['zip_filename'], ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    private function generate(Request $request, ?string $txtPath = null): array
    {
        $period = $request->query('period');
        if (!is_string($period)) {
            throw new InvalidArgumentException('El periodo debe tener el formato AAAAMM.');
        }

        [$year, $month] = RvieReplacementBuilder::validatePeriod($period);

        return (new RvieReplacementBuilder())->build($year, $month, $txtPath);
    }
}
