<?php
namespace App\Http\Controllers\Tenant;

use App\CoreFacturalo\Helpers\Storage\StorageDocument;
use App\Http\Controllers\Controller;
use App\CoreFacturalo\Facturalo;
use App\Models\Tenant\Dispatch;
use App\Models\Tenant\Document;
use App\Models\Tenant\Order;
use App\Models\Tenant\Perception;
use App\Models\Tenant\Purchase;
use App\Models\Tenant\Quotation;
use App\Models\Tenant\Retention;
use App\Models\Tenant\SaleNote;
use App\Models\Tenant\Summary;
use App\Models\Tenant\Voided;
use Modules\Expense\Models\Expense;
use Modules\Order\Models\OrderForm;
use Modules\Order\Models\OrderNote;
use Modules\Purchase\Models\FixedAssetPurchase;
use Throwable;

class DownloadController extends Controller
{
    use StorageDocument;

    private const MODEL_MAP = [
        'document'             => Document::class,
        'sale_note'            => SaleNote::class,
        'salenote'             => SaleNote::class,
        'quotation'            => Quotation::class,
        'purchase'             => Purchase::class,
        'dispatch'             => Dispatch::class,
        'retention'            => Retention::class,
        'perception'           => Perception::class,
        'summary'              => Summary::class,
        'voided'               => Voided::class,
        'order'                => Order::class,
        'order_note'           => OrderNote::class,
        'ordernote'            => OrderNote::class,
        'order_form'           => OrderForm::class,
        'orderform'            => OrderForm::class,
        'expense'              => Expense::class,
        'fixed_asset_purchase' => FixedAssetPurchase::class,
        'fixedassetpurchase'   => FixedAssetPurchase::class,
    ];

    public function downloadExternal($model, $type, $external_id, $format = null) {
        $document = $this->resolveDocument($model, $external_id);

        if ($format != null) {
            $this->reloadPDF($document, 'invoice', $format);
        }

        return $this->download($type, $document);
    }

    public function download($type, $document) {
        $folders = [
            'pdf'       => 'pdf',
            'xml'       => 'signed',
            'cdr'       => 'cdr',
            'quotation' => 'quotation',
            'sale_note' => 'sale_note',
        ];

        abort_unless(isset($folders[$type]), 404, 'Tipo de archivo a descargar es inválido.');
        $folder = $folders[$type];

        $content = $this->readStorageContent($document->filename, $folder);
        abort_if($content === null || $content === '', 404, 'Archivo no encontrado.');

        return $this->downloadStorage($document->filename, $folder);
    }

    public function toPrint($model, $external_id, $format = null) {
        $document = $this->resolveDocument($model, $external_id);

        if ($format != null) {
            $this->reloadPDF($document, 'invoice', $format);
        }

        $content = $this->readStorageContent($document->filename, 'pdf');
        abort_if($content === null || $content === '', 404, 'Archivo PDF no encontrado.');

        $safeFilename = basename((string) $document->filename) ?: 'document';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $safeFilename . '.pdf"',
        ]);
    }

    private function resolveDocument($model, $external_id)
    {
        $key = strtolower((string) $model);
        abort_unless(isset(self::MODEL_MAP[$key]), 404, 'Tipo de documento inválido.');

        $modelClass = self::MODEL_MAP[$key];
        $document = $modelClass::where('external_id', $external_id)->first();
        abort_unless((bool) $document, 404, "El código {$external_id} es inválido, no se encontro documento relacionado.");

        return $document;
    }

    private function readStorageContent($filename, $folder)
    {
        if (!$filename) {
            return null;
        }

        try {
            return $this->getStorage($filename, $folder);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Reload PDF
     * @param  ModelTenant $document
     * @param  string $format
     * @return void
     */
    private function reloadPDF($document, $type, $format) {
        (new Facturalo)->createPdf($document, $type, $format);
    }
}
