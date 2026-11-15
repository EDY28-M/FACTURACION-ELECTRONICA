<?php

namespace Modules\Report\Http\Controllers;

use App\CoreFacturalo\Services\Sire\RceReplacementBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class ReportSireRceController extends Controller
{
    public function index()
    {
        return view('report::sire.rce');
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
        $txtPath = tempnam(sys_get_temp_dir(), 'rcetxt');
        $zipPath = null;
        $streamed = false;

        try {
            $result = $this->generate($request, $txtPath);

            if ($result['errors_count'] > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hay comprobantes que impiden generar el archivo. Corrigelos e intenta nuevamente.',
                    'errors' => $result['errors'],
                ], 422);
            }

            if ($result['demo'] && !filter_var($request->query('force_demo'), FILTER_VALIDATE_BOOLEAN)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La empresa esta en ambiente de pruebas. Confirma que deseas descargar el archivo de todas formas.',
                    'errors' => [],
                ], 422);
            }

            $zipPath = RceReplacementBuilder::zipFile($result['filename'], $txtPath);

            $response = response()->download($zipPath, $result['zip_filename'], ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend(true);
            $streamed = true;

            return $response;
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } finally {
            @unlink($txtPath);
            if ($zipPath !== null && !$streamed) {
                @unlink($zipPath);
            }
        }
    }

    private function generate(Request $request, ?string $txtPath = null): array
    {
        $period = $request->query('period');
        if (!is_string($period)) {
            throw new InvalidArgumentException('El periodo debe tener el formato AAAAMM.');
        }

        [$year, $month] = RceReplacementBuilder::validatePeriod($period);

        return (new RceReplacementBuilder())->build($year, $month, $txtPath);
    }
}
