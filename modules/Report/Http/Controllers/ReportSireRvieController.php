<?php

namespace Modules\Report\Http\Controllers;

use App\CoreFacturalo\Services\Sire\RvieReplacementBuilder;
use App\CoreFacturalo\Services\Sire\SireReconciliationService;
use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreAuthException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreException;
use App\CoreFacturalo\WS\Sire\SireClient;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Company;
use App\Models\Tenant\SireRequest;
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
            report($e);

            return response()->json(['success' => false, 'message' => 'No se pudo consultar el SIRE.'], 422);
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

            $zipPath = RvieReplacementBuilder::zipFile($result['filename'], $txtPath);

            $response = response()->download($zipPath, $result['zip_filename'], ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend(true);
            $streamed = true;

            return $response;
        } catch (InvalidArgumentException|RuntimeException $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'No se pudo consultar el SIRE.'], 422);
        } finally {
            @unlink($txtPath);
            if ($zipPath !== null && !$streamed) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * Lists the SUNAT propuesta requests of a period and tells whether credentials are configured.
     */
    public function sunatIndex(Request $request)
    {
        $period = $request->query('period');
        if (!is_string($period) || !preg_match('/^\d{6}$/', $period)) {
            return response()->json(['success' => false, 'message' => 'El periodo debe tener el formato AAAAMM.'], 422);
        }

        $company = Company::active();
        $configured = true;
        $configMessage = null;
        try {
            if (!$company) {
                throw new GreConfigurationException('No hay una empresa configurada.');
            }
            SireClient::settingsForCompany($company);
        } catch (GreException $e) {
            $configured = false;
            $configMessage = SireReconciliationService::friendlyError($e);
        }

        $requests = SireRequest::where('book', SireClient::BOOK_RVIE)->where('period', $period)
            ->orderByDesc('id')->limit(20)->get();

        return response()->json([
            'success' => true,
            'configured' => $configured,
            'config_message' => $configMessage,
            'demo' => $company ? (string) $company->soap_type_id !== '02' : false,
            'requests' => $requests->map(function ($r) {
                return $this->presentRequest($r);
            })->values(),
        ]);
    }

    public function sunatRequest(Request $request)
    {
        $period = $request->input('period');
        if (!is_string($period)) {
            return response()->json(['success' => false, 'message' => 'El periodo debe tener el formato AAAAMM.'], 422);
        }

        return $this->runSunat(function () use ($period) {
            return (new SireReconciliationService())->request($period);
        });
    }

    public function sunatRefresh($id)
    {
        $record = SireRequest::find($id);
        if (!$record) {
            return response()->json(['success' => false, 'message' => 'La solicitud no existe.'], 404);
        }

        return $this->runSunat(function () use ($record) {
            return (new SireReconciliationService())->refresh($record);
        });
    }

    public function sunatShow($id)
    {
        $record = SireRequest::find($id);
        if (!$record) {
            return response()->json(['success' => false, 'message' => 'La solicitud no existe.'], 404);
        }

        return response()->json(['success' => true, 'request' => $this->presentRequest($record, true)]);
    }

    /**
     * Runs a SUNAT action turning every expected failure (validation, credentials, network, SUNAT errors)
     * into a JSON message; the page never answers 500 for them.
     */
    private function runSunat(callable $action)
    {
        try {
            $result = $action();
        } catch (InvalidArgumentException|RuntimeException $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'No se pudo consultar el SIRE.'], 422);
        } catch (GreException $e) {
            $userFixable = $e instanceof GreConfigurationException || $e instanceof GreAuthException
                || ($e instanceof GreApiException && $e->isValidationError());
            $status = $userFixable ? 422 : 502;

            return response()->json(['success' => false, 'message' => SireReconciliationService::friendlyError($e)], $status);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'request' => $this->presentRequest($result['request'], true),
        ]);
    }

    private function presentRequest(SireRequest $r, bool $withSummary = false): array
    {
        $labels = [
            SireRequest::STATUS_REQUESTED => 'Solicitada',
            SireRequest::STATUS_PROCESSING => 'En proceso en SUNAT',
            SireRequest::STATUS_READY => 'Lista para descargar',
            SireRequest::STATUS_FAILED => 'Con error',
            SireRequest::STATUS_RECONCILED => 'Comparada',
        ];

        $data = [
            'id' => $r->id,
            'period' => $r->period,
            'ticket' => $r->ticket,
            'status' => $r->status,
            'status_label' => $labels[$r->status] ?? $r->status,
            'status_detail' => $r->status_detail,
            'file_name' => $r->file_name,
            'requested_at' => $r->requested_at ? $r->requested_at->format('d/m/Y H:i') : null,
            'finished_at' => $r->finished_at ? $r->finished_at->format('d/m/Y H:i') : null,
            'open' => $r->isOpen(),
            'has_summary' => !empty($r->summary),
        ];
        if ($withSummary && !empty($r->summary)) {
            $data['summary'] = $r->summary;
        }

        return $data;
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
