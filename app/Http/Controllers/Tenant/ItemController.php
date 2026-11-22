<?php
namespace App\Http\Controllers\Tenant;

use App\Imports\ItemsImport;
use App\Models\Tenant\Catalogs\AffectationIgvType;
use App\Models\Tenant\Catalogs\AttributeType;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Catalogs\SystemIscType;
use App\Models\Tenant\Catalogs\UnitType;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemImage;

use Modules\Item\Models\ItemLot;

use App\CoreFacturalo\Helpers\Storage\TempImageHelper;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Requests\Tenant\ItemRequest;
use App\Http\Resources\Tenant\ItemCollection;
use App\Http\Resources\Tenant\ItemResource;
use App\Models\Tenant\User;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\Configuration;
use App\Models\Tenant\ItemUnitType;
use Exception;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel;
use Modules\Account\Models\Account;
use App\Models\Tenant\ItemTag;
use App\Models\Tenant\Catalogs\Tag;
use Modules\Item\Models\Category;
use Modules\Item\Models\Brand;
use Modules\Inventory\Models\Warehouse as WarehouseModule;
use App\Models\Tenant\Establishment;
use Modules\Item\Models\ItemLotsGroup;
use Carbon\Carbon;
use App\Exports\ItemExport;
use App\Exports\ItemExportWp;
use App\Exports\ItemExportBarCode;
use Modules\Finance\Helpers\UploadFileHelper;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Modules\Inventory\Models\ItemWarehouse;


class ItemController extends Controller
{
    public function index()
    {
        return view('tenant.items.index');
    }

    public function indexServices()
    {
        $type = 'ZZ';
        return view('tenant.items.index', compact('type'));
    }

    public function index_ecommerce()
    {
        return view('tenant.items_ecommerce.index');
    }

    public function columns()
    {
        return [
            'description' => 'Nombre',
            'internal_id' => 'Código interno',
            'barcode' => 'Código de barras',
            'model' => 'Modelo',
            'brand' => 'Marca',
            'date_of_due' => 'Fecha vencimiento',
            'lot_code' => 'Código lote',
            'active' => 'Habilitados',
            'inactive' => 'Inhabilitados',
        ];
    }

    public function records(Request $request)
    {
        $records = $this->getRecords($request);

        return new ItemCollection($records->paginate(config('tenant.items_per_page')));
    }


    public function getRecords($request){

        switch ($request->column) {

            case 'brand':
                $records = Item::whereHas('brand',function($q) use($request){
                                    $q->where('name', 'like', "%{$request->value}%");
                                })
                                ->whereTypeUser()
                                ->whereNotIsSet();
                break;

            case 'active':
                $records = Item::whereTypeUser()
                                ->whereNotIsSet()
                                ->whereIsActive();
                break;

            case 'inactive':
                $records = Item::whereTypeUser()
                                ->whereNotIsSet()
                                ->whereIsNotActive();
                break;

            default:
                $allowedColumns = ['description', 'internal_id', 'barcode', 'model', 'date_of_due', 'lot_code'];
                $column = in_array($request->column, $allowedColumns, true) ? $request->column : 'description';
                $records = Item::whereTypeUser()
                                ->whereNotIsSet()
                                ->where($column, 'like', "%{$request->value}%");
                break;
        }
        if ($request->type) {
            $records = $records->whereService();
        }

        return $records->orderBy('description');

    }

    public function create()
    {
        return view('tenant.items.form');
    }

    /**
     * Search SUNAT Catalogo 25 (system database) by description or code.
     */
    public function sunatProductCodes(\Illuminate\Http\Request $request)
    {
        return response()->json([
            'data' => \App\CoreFacturalo\Helpers\Sunat\ProductCodeSearch::run($request->query('q'), 30),
        ]);
    }

    public function tables()
    {
        $unit_types = UnitType::whereActive()->orderByDescription()->get();
        $currency_types = CurrencyType::whereActive()->orderByDescription()->get();
        $attribute_types = AttributeType::whereActive()->orderByDescription()->get();
        $system_isc_types = SystemIscType::whereActive()->orderByDescription()->get();
        $affectation_igv_types = AffectationIgvType::whereActive()->get();
        // $warehouse = Warehouse::where('establishment_id', auth()->user()->establishment_id)->first();
        $warehouses = Warehouse::all();
        $accounts = Account::all();
        $tags = Tag::all();
        $categories = Category::all();
        $brands = Brand::all();
        $configuration = Configuration::select('affectation_igv_type_id')->firstOrFail();

        return compact('unit_types', 'currency_types', 'attribute_types', 'system_isc_types',
                        'affectation_igv_types','warehouses', 'accounts', 'tags', 'categories', 'brands', 'configuration');
    }

    public function record($id)
    {
        $record = new ItemResource(Item::findOrFail($id));

        return $record;
    }

    public function store(ItemRequest $request) {
        $id = $request->input('id');
        if (!$request->barcode) {
            if ($request->internal_id) {
                $request->merge(['barcode' => $request->internal_id]);
            }
        }

        $temp_path = $request->input('temp_path');
        $resolvedMainImage = $temp_path ? TempImageHelper::resolveValidated($temp_path) : null;

        $multi_images = is_array($request->multi_images) ? $request->multi_images : [];
        $resolvedMultiImages = [];
        foreach ($multi_images as $im) {
            if (!is_array($im) || empty($im['temp_path'])) {
                abort(422, 'Imagen adicional inválida.');
            }
            $resolvedMultiImages[] = [
                'filename' => $im['filename'] ?? '',
                'resolved' => TempImageHelper::resolveValidated($im['temp_path']),
            ];
        }

        $item = DB::connection('tenant')->transaction(function () use ($request, $id, $resolvedMainImage, $resolvedMultiImages) {
            $item = Item::firstOrNew(['id' => $id]);
            $item->item_type_id = '01';
            $item->amount_plastic_bag_taxes = Configuration::firstOrFail()->amount_plastic_bag_taxes;
            $item->fill($request->all());

            $directory = 'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR;

            if ($resolvedMainImage) {
                $realTempPath = $resolvedMainImage['path'];
                $ext = $resolvedMainImage['extension'];
                $file_content = file_get_contents($realTempPath);
                $datenow = date('YmdHis');
                $slug = Str::slug($item->description) ?: 'item';

                $file_name = $slug.'-'.$datenow.'.'.$ext;
                if (!Storage::put($directory.$file_name, $file_content)) {
                    throw new \RuntimeException('Unable to store item image.');
                }
                $item->image = $file_name;

                //--- IMAGE SIZE MEDIUM
                $image = \Image::make($realTempPath);
                $file_name = $slug.'-'.$datenow.'_medium.'.$ext;
                $image->resize(512, null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
                if (!Storage::put($directory.$file_name, (string) $image->encode('jpg', 30))) {
                    throw new \RuntimeException('Unable to store item image.');
                }
                $item->image_medium = $file_name;

                //--- IMAGE SIZE SMALL
                $image = \Image::make($realTempPath);
                $file_name = $slug.'-'.$datenow.'_small.'.$ext;
                $image->resize(256, null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
                if (!Storage::put($directory.$file_name, (string) $image->encode('jpg', 20))) {
                    throw new \RuntimeException('Unable to store item image.');
                }
                $item->image_small = $file_name;
            } elseif (!$request->input('image') && !$request->input('temp_path') && !$request->input('image_url')) {
                $item->image = 'imagen-no-disponible.jpg';
            }

            $item->save();

            $item_unit_types = is_array($request->item_unit_types) ? $request->item_unit_types : [];
            foreach ($item_unit_types as $value) {
                $unitTypeId = $value['id'] ?? null;
                $item_unit_type = ($item->exists && $unitTypeId)
                    ? (ItemUnitType::where('item_id', $item->id)->find($unitTypeId) ?? new ItemUnitType())
                    : new ItemUnitType();
                $item_unit_type->item_id = $item->id;
                $item_unit_type->description = $value['description'];
                $item_unit_type->unit_type_id = $value['unit_type_id'];
                $item_unit_type->quantity_unit = $value['quantity_unit'];
                $item_unit_type->price1 = $value['price1'];
                $item_unit_type->price2 = $value['price2'];
                $item_unit_type->price3 = $value['price3'];
                $item_unit_type->price_default = $value['price_default'];
                $item_unit_type->save();
            }

            if (is_array($request->tags_id) && $request->tags_id) {
                ItemTag::destroy(ItemTag::where('item_id', $item->id)->pluck('id'));
                foreach ($request->tags_id as $value) {
                    ItemTag::create(['item_id' => $item->id, 'tag_id' => $value]);
                }
            }

            $establishmentId = optional(auth()->user())->establishment_id;
            $establishment = $establishmentId ? Establishment::where('id', $establishmentId)->first() : null;
            $warehouse = $establishment ? Warehouse::where('establishment_id', $establishment->id)->first() : null;
            $v_lots = is_array($request->lots) ? $request->lots : [];

            if (!$id) {
                foreach ($v_lots as $lot) {
                    $item->lots()->create([
                        'date' => $lot['date'],
                        'series' => $lot['series'],
                        'item_id' => $item->id,
                        'warehouse_id' => $warehouse ? $warehouse->id : null,
                        'has_sale' => false,
                        'state' => $lot['state'],
                    ]);
                }
                $lots_enabled = isset($request->lots_enabled) ? $request->lots_enabled : false;
                if ($lots_enabled) {
                    ItemLotsGroup::create([
                        'code' => $request->lot_code,
                        'quantity' => $request->stock,
                        'date_of_due' => $request->date_of_due,
                        'item_id' => $item->id
                    ]);
                }
            } else {
                foreach ($v_lots as $lot) {
                    $lotId = $lot['id'] ?? null;
                    if (!empty($lot['deleted'])) {
                        if ($lotId) {
                            $existingLot = ItemLot::where('item_id', $item->id)->find($lotId);
                            if ($existingLot) {
                                $existingLot->delete();
                            }
                        }
                    } elseif ($lotId) {
                        $existingLot = ItemLot::where('item_id', $item->id)->find($lotId);
                        if ($existingLot) {
                            $existingLot->update([
                                'date' => $lot['date'],
                                'series' => $lot['series'],
                                'state' => $lot['state'],
                            ]);
                        }
                    } else {
                        $item->lots()->create([
                            'date' => $lot['date'],
                            'series' => $lot['series'],
                            'item_id' => $item->id,
                            'warehouse_id' => $warehouse ? $warehouse->id : null,
                            'has_sale' => false,
                            'state' => $lot['state'],
                        ]);
                    }
                }

                $lots_enabled = isset($request->lots_enabled) ? $request->lots_enabled : false;
                if ($lots_enabled) {
                    ItemLotsGroup::where('item_id', $item->id)->delete();
                    ItemLotsGroup::create([
                        'code' => $request->lot_code,
                        'quantity' => $request->stock,
                        'date_of_due' => $request->date_of_due,
                        'item_id' => $item->id
                    ]);
                }
            }

            foreach ($resolvedMultiImages as $im) {
                $baseName = Str::slug(pathinfo((string) $im['filename'], PATHINFO_FILENAME)) ?: 'item-image';
                $file_name = $baseName.'-'.date('YmdHis').'-'.Str::random(6).'.'.$im['resolved']['extension'];
                $file_content = file_get_contents($im['resolved']['path']);
                if (!Storage::put($directory.$file_name, $file_content)) {
                    throw new \RuntimeException('Unable to store item image.');
                }

                ItemImage::create(['item_id' => $item->id, 'image' => $file_name]);
            }

            if (!$item->barcode) {
                $item->barcode = str_pad($item->id, 12, '0', STR_PAD_LEFT);
            }

            $item->update();

            return $item;
        });

        if ($resolvedMainImage) {
            TempImageHelper::deleteOwned($resolvedMainImage['path']);
        }
        foreach ($resolvedMultiImages as $im) {
            TempImageHelper::deleteOwned($im['resolved']['path']);
        }

        return [
            'success' => true,
            'message' => ($id) ? 'Producto editado con éxito' : 'Producto registrado con éxito',
            'id' => $item->id
        ];
    }

    public function destroy($id)
    {
        try {
            DB::connection('tenant')->transaction(function () use ($id) {
                $item = Item::findOrFail($id);
                $this->deleteRecordInitialKardex($item);
                $item->delete();
            });

            return [
                'success' => true,
                'message' => 'Producto eliminado con éxito'
            ];

        } catch (Exception $e) {

            return ($e->getCode() == '23000') ? ['success' => false,'message' => 'El producto esta siendo usado por otros registros, no puede eliminar'] : ['success' => false,'message' => 'Error inesperado, no se pudo eliminar el producto'];

        }


    }

    public function destroyItemUnitType($id)
    {
        $item_unit_type = ItemUnitType::findOrFail($id);
        $item_unit_type->delete();

        return [
            'success' => true,
            'message' => 'Registro eliminado con éxito'
        ];
    }


    public function import(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|numeric|min:1'
        ]);
        if ($request->hasFile('file')) {
            try {
                $import = new ItemsImport();
                $import->import($request->file('file'), null, Excel::XLSX);
                $data = $import->getData();
                return [
                    'success' => true,
                    'message' =>  __('app.actions.upload.success'),
                    'data' => $data
                ];
            } catch (Exception $e) {
                report($e);

                return [
                    'success' => false,
                    'message' => 'No se pudo importar el archivo. Verifique el formato.',
                ];
            }
        }
        return [
            'success' => false,
            'message' =>  __('app.actions.upload.error'),
        ];
    }

    public function upload(Request $request)
    {

        $validate_upload = UploadFileHelper::validateUploadFile($request, 'file', 'jpg,jpeg,png,gif,webp');

        if(!$validate_upload['success']){
            return $validate_upload;
        }

        if ($request->hasFile('file')) {
            $new_request = [
                'file' => $request->file('file'),
                'type' => $request->input('type'),
            ];

            return $this->upload_image($new_request);
        }
        return [
            'success' => false,
            'message' =>  __('app.actions.upload.error'),
        ];
    }

    function upload_image($request)
    {
        return TempImageHelper::storeTemp($request['file'], $request['type'] ?? 'item_');
    }

    private function deleteRecordInitialKardex($item){

        if($item->kardex->count() == 1){
            ($item->kardex[0]->type == null) ? $item->kardex[0]->delete() : false;
        }

    }


    public function visibleStore(Request $request)
    {
        $item = Item::findOrFail($request->id);

        if(!$item->internal_id && $request->apply_store){
            return [
                'success' => false,
                'message' =>'Para habilitar la visibilidad, debe asignar un codigo interno al producto',
            ];
        }

        $visible = $request->apply_store == true ? 1 : 0 ;
        $item->apply_store = $visible;
        $item->save();

        return [
            'success' => true,
            'message' => ($visible > 0 )?'El Producto ya es visible en tienda virtual' : 'El Producto ya no es visible en tienda virtual',
            'id' => $request->id
        ];

    }

    public function duplicate(Request $request)
    {
       // return $request->id;
       $obj = Item::findOrFail($request->id);
       $new = $obj->replicate();
       $new->save();

        return [
            'success' => true,
            'data' => [
                'id' => $new->id,
            ],
        ];

    }

    public function disable($id)
    {
        try {

            $item = Item::findOrFail($id);
            $item->active = 0;
            $item->save();

            return [
                'success' => true,
                'message' => 'Producto inhabilitado con éxito'
            ];

        } catch (Exception $e) {

            return  ['success' => false, 'message' => 'Error inesperado, no se pudo inhabilitar el producto'];

        }
    }

    public function images($item)
    {
        $records = ItemImage::where('item_id', $item)->get()->transform(function($row){
            return [
                'id' => $row->id,
                'item_id' => $row->item_id,
                'image' => $row->image,
                'id' => $row->id,
                'name' => $row->image,
                'url'=> asset('storage'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR.$row->image)
            ];
        });
        return [
            'success' => true,
            'data' => $records
        ];
    }

    public function delete_images($id)
    {
        $record = ItemImage::findOrFail($id);
        $record->delete();

        return [
            'success' => true,
            'message' => 'Imagen eliminada con éxito'
        ];
    }


    public function enable($id)
    {
        try {

            $item = Item::findOrFail($id);
            $item->active = 1;
            $item->save();

            return [
                'success' => true,
                'message' => 'Producto habilitado con éxito'
            ];

        } catch (Exception $e) {

            return  ['success' => false, 'message' => 'Error inesperado, no se pudo habilitar el producto'];

        }
    }

    public function export(Request $request)
    {
        $d_start = null;
        $d_end = null;
        $period = $request->period;

        switch ($period) {
            case 'month':
                $d_start = Carbon::parse($request->month_start.'-01')->format('Y-m-d');
                $d_end = Carbon::parse($request->month_start.'-01')->endOfMonth()->format('Y-m-d');
                break;
            case 'between_months':
                $d_start = Carbon::parse($request->month_start.'-01')->format('Y-m-d');
                $d_end = Carbon::parse($request->month_end.'-01')->endOfMonth()->format('Y-m-d');
                break;
        }

        // $date = $request->month_start.'-01';
        // $start_date = Carbon::parse($date);
        // $end_date = Carbon::parse($date)->addMonth()->subDay();

        $items = Item::whereTypeUser()->whereNotIsSet();

        $records = ($period == 'all') ? $items->get() : $items->whereBetween('created_at', [$d_start, $d_end])->get();

        return (new ItemExport)
                ->records($records)
                ->download('Reporte_Items_'.Carbon::now().'.xlsx');

    }

    public function exportWp(Request $request)
    {
        $date = $request->month_start.'-01';
        $start_date = Carbon::parse($date);
        $end_date = Carbon::parse($date)->addMonth()->subDay();

        $records = Item::whereBetween('created_at', [$start_date, $end_date])->get();

        return (new ItemExportWp)
                ->records($records)
                ->download('Reporte_Items_'.Carbon::now().'.csv', Excel::CSV);

    }

    public function exportBarCode(Request $request)
    {
        ini_set("pcre.backtrack_limit", "50000000");

        $start = $request[0];
        $end = $request[1];

        $records = Item::whereBetween('id', [$start, $end])->get();

        $pdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => [
                    104.1,
                    101.6
                    ],
                'margin_top' => 2,
                'margin_right' => 2,
                'margin_bottom' => 0,
                'margin_left' => 2
            ]);
        $html = view('tenant.items.exports.items-barcode', compact('records'))->render();

        $pdf->WriteHTML($html, HTMLParserMode::HTML_BODY);

        $pdf->output('etiquetas_'.now()->format('Y_m_d').'.pdf', 'I');

    }

    public function printBarCode(Request $request)
    {
        ini_set("pcre.backtrack_limit", "50000000");
        $id = $request->id;

        $record = Item::findOrFail($id);
        $warehouseId = optional(optional(optional(auth()->user())->establishment)->warehouse)->id;
        $item_warehouse = $warehouseId
            ? ItemWarehouse::where([['item_id', $id], ['warehouse_id', $warehouseId]])->first()
            : null;

        if(!$item_warehouse){
            return [
                'success' => false,
                'message' => "El producto seleccionado no esta disponible en su almacen!"
            ];
        }

        if($item_warehouse->stock < 1){
            return [
                'success' => false,
                'message' => "El producto seleccionado no tiene stock disponible en su almacen, no puede generar etiquetas!"
            ];
        }

        $stock = $item_warehouse->stock;

        $pdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => [
                    104.1,
                    24
                    ],
                'margin_top' => 2,
                'margin_right' => 2,
                'margin_bottom' => 0,
                'margin_left' => 2
            ]);
        $html = view('tenant.items.exports.items-barcode-id', compact('record', 'stock'))->render();

        $pdf->WriteHTML($html, HTMLParserMode::HTML_BODY);

        $pdf->output('etiquetas_'.now()->format('Y_m_d').'.pdf', 'I');

    }

    public function itemLast()
    {
        $record = Item::latest()->first();
        return json_encode(['data' => optional($record)->id]);
    }

    public function tablesImport()
    {
        $user = auth()->user();
        $warehouses = Warehouse::select('id', 'description');
        if ($user->type !== 'admin') {
            $warehouses = $warehouses->where('id', $user->establishment_id);
        }

        return response()->json([
            'warehouses' => $warehouses->get(),
        ], 200);
    }
}
