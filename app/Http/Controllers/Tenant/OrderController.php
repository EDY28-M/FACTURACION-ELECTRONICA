<?php
namespace App\Http\Controllers\Tenant;

use Exception;

use App\Models\Tenant\Order;
use Illuminate\Http\Request;
use App\Models\Tenant\Series;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Establishment;

use App\Http\Resources\Tenant\OrderCollection;
use App\CoreFacturalo\Helpers\Storage\StorageDocument;
use Modules\Inventory\Models\Warehouse as ModuleWarehouse;
use App\Models\Tenant\Item;
use App\Models\Tenant\Catalogs\DocumentType;
use App\Models\Tenant\Document;
use App\Services\Ecommerce\OrderDocumentBuilder;
use App\Services\Ecommerce\OrderFulfillment;
use App\Services\Payments\CartPricing;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class OrderController extends Controller
{

  use StorageDocument;

  protected $company;

    public function index()
    {
        return view('tenant.orders.index');
    }

    public function columns()
    {
        return [
            'id' => 'Codigo de Pedido',
            'customer' => 'Cliente',
            'number_document' => 'Comprobante Electronico',
        ];
    }

    public function tables()
    {
      $establishments = Establishment::where('id', auth()->user()->establishment_id)->get();
      $series = collect(Series::all())->transform(function($row) {
          return [
              'id' => $row->id,
              'contingency' => (bool) $row->contingency,
              'document_type_id' => $row->document_type_id,
              'establishment_id' => $row->establishment_id,
              'number' => $row->number
          ];
      });

      $document_types = DocumentType::all();

      return compact('series', 'establishments', 'document_types');

    }

    public function item($internal_id)
    {
        $establishment_id = auth()->user()->establishment_id;
        $warehouse = ModuleWarehouse::where('establishment_id', $establishment_id)->first();

        $row = Item::where('internal_id', $internal_id)->first();
        if (! $row) {
            return response()->json(['success' => false, 'message' => 'El producto del pedido ya no existe.'], 404);
        }

        return [
            'id' => $row->id,
            'description' => $row->description,
            'sale_unit_price' => round($row->sale_unit_price, 2),
            'lots' => $row->item_lots->where('has_sale', false)->where('warehouse_id', optional($warehouse)->id)->transform(function($row) {
                return [
                    'id' => $row->id,
                    'series' => $row->series,
                    'date' => $row->date,
                    'item_id' => $row->item_id,
                    'warehouse_id' => $row->warehouse_id,
                    'has_sale' => (bool)$row->has_sale,
                    'lot_code' => ($row->item_loteable_type) ? (isset($row->item_loteable->lot_code) ? $row->item_loteable->lot_code:null):null
                ];
            })->values(),
            'series_enabled' => (bool) $row->series_enabled,
        ];
    }

    public function records(Request $request)
    {
        $column = array_key_exists((string) $request->column, $this->columns()) ? $request->column : 'id';
        // The customer is stored as JSON: search its name instead of the raw column.
        $field = $column === 'customer' ? 'customer->apellidos_y_nombres_o_razon_social' : $column;
        $records = Order::where($field, 'like', '%'.addcslashes((string) $request->value, '\\%_').'%')->latest();

        // Optional exact filter by order status (not shown in the grid filters yet).
        if ($request->filled('status_order_id') && is_numeric($request->status_order_id)) {
            $records->where('status_order_id', (int) $request->status_order_id);
        }

        return new OrderCollection($records->paginate(config('tenant.items_per_page')));
    }

    public function updateStatusOrders(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'record' => 'required|array',
            'record.id' => 'required|integer|min:1',
            'record.status_order_id' => 'required|integer|exists:tenant.status_orders,id',
        ], [
            'record.required' => 'Indique el pedido a actualizar.',
            'record.id.required' => 'Indique el pedido a actualizar.',
            'record.status_order_id.required' => 'Indique el nuevo estatus del pedido.',
            'record.status_order_id.exists' => 'El estatus indicado no existe.',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        try {
            // Status only: stock moves when the comprobante is issued, never on dispatch.
            OrderFulfillment::changeStatus(
                (int) $request->input('record.id'),
                (int) $request->input('record.status_order_id')
            );
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'El pedido no existe.'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return [
            'success' => true,
            'message' => 'Estatus actualizado',
        ];
    }

    /**
     * Comprobante payload for an order, rebuilt on the server from the stored, server-priced
     * order lines and the validated checkout customer (never from browser data).
     */
    public function documentDraft($id)
    {
        $order = Order::find($id);
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'El pedido no existe.'], 404);
        }
        if ($order->document_external_id) {
            return response()->json([
                'success' => false,
                'message' => 'El pedido ya tiene un comprobante asociado ('.$order->number_document.').',
            ], 422);
        }

        try {
            $ids = collect((array) $order->items)->map(function ($line) {
                return (int) data_get($line, 'id');
            })->filter()->all();
            $items = Item::whereIn('id', $ids)->get()->keyBy('id');

            if (! OrderDocumentBuilder::hasServerPrices($order)) {
                // Legacy order: lines were stored without server prices. Reprice from the catalogue
                // and only accept it when it reproduces the amount the buyer was charged.
                $legacy = collect((array) $order->items)->map(function ($line) {
                    return ['id' => data_get($line, 'id'), 'cantidad' => data_get($line, 'cantidad')];
                })->all();
                $rate = 1.0;
                if ($items->contains(function ($item) { return $item->currency_type_id !== 'PEN'; })) {
                    throw new InvalidArgumentException('Este pedido antiguo tiene productos en dólares; emita el comprobante manualmente.');
                }
                $priced = CartPricing::total($legacy, $items, $rate, CartPricing::defaultIgvRate());
                if (abs($priced['total'] - (float) $order->total) > 0.01) {
                    throw new InvalidArgumentException('Los precios actuales ya no coinciden con el total del pedido; emita el comprobante manualmente.');
                }
                $order->items = $priced['lines'];
            }

            $document = OrderDocumentBuilder::build($order, $items, date('Y-m-d'), date('H:i:s'), CartPricing::defaultIgvRate());
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return ['success' => true, 'document' => $document];
    }

    /**
     * Links the comprobante issued for an order (back office action, module "ecommerce").
     */
    public function attachDocument(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'document_external_id' => 'required|string|max:36',
        ], [
            'document_external_id.required' => 'Falta el identificador del comprobante.',
            'document_external_id.max' => 'El identificador del comprobante no es válido.',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        try {
            $order = OrderFulfillment::attachDocument((int) $id, $request->input('document_external_id'), function ($externalId) {
                return Document::where('external_id', $externalId)->first();
            });
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'El pedido no existe.'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return [
            'success' => true,
            'message' => 'Comprobante asociado al pedido',
            'number_document' => $order->number_document,
            'status_order_id' => $order->status_order_id,
        ];
    }

}
