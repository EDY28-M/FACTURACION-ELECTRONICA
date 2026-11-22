<?php

namespace App\Http\Controllers\Tenant;

use App\CoreFacturalo\Services\Cpe\BulkValidationInterrupted;
use App\CoreFacturalo\Services\Cpe\PurchaseValidationService;
use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreAuthException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreException;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Purchase;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Validates supplier documents against SUNAT's "Consulta Integrada de Validez de Comprobantes de Pago".
 * SUNAT/credential problems are answered as JSON (422 when the user can fix them, 502 otherwise), never as 500.
 */
class PurchaseSunatValidationController extends Controller
{
    public function validateOne($id)
    {
        $purchase = Purchase::query()->setEagerLoads([])->find($id);
        if (!$purchase) {
            return response()->json(['success' => false, 'message' => 'La compra no existe.'], 404);
        }

        try {
            $result = (new PurchaseValidationService())->validatePurchase($purchase);
        } catch (GreException $e) {
            return $this->failure($e);
        }

        return response()->json([
            'success' => true,
            'message' => $result['status'] === PurchaseValidationService::STATUS_NO_APLICA
                ? ($result['detail']['message'] ?? 'El comprobante no se puede validar.')
                : 'Comprobante validado en SUNAT: '.$result['label'].'.',
            'validation' => $result,
        ]);
    }

    public function validatePeriod(Request $request)
    {
        $period = $request->input('period');
        if (!is_string($period) || !preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $period)) {
            return response()->json(['success' => false, 'message' => 'El periodo debe tener el formato AAAAMM.'], 422);
        }

        set_time_limit(60);

        try {
            $result = (new PurchaseValidationService())->validatePeriod(
                $period,
                $request->boolean('only_pending', true),
                $request->input('after_id')
            );
        } catch (InvalidArgumentException $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'No se pudo validar el comprobante con SUNAT.'], 422);
        } catch (BulkValidationInterrupted $e) {
            // Progress already stored is reported with the error so the UI keeps its counters.
            return $this->failure($e->getCause(), $e->getPartial());
        } catch (GreException $e) {
            return $this->failure($e);
        }

        return response()->json(['success' => true] + $result);
    }

    private function failure(GreException $e, array $partial = [])
    {
        $userFixable = $e instanceof GreConfigurationException || $e instanceof GreAuthException
            || ($e instanceof GreApiException && $e->isValidationError());

        return response()->json([
            'success' => false,
            'message' => PurchaseValidationService::friendlyError($e),
        ] + $partial, $userFixable ? 422 : 502);
    }
}
