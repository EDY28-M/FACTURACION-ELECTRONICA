<?php
namespace App\Http\Controllers\Tenant;

use App\Imports\ItemsImport;
use App\Models\Tenant\Catalogs\AffectationIgvType;
use App\Models\Tenant\Catalogs\AttributeType;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Catalogs\SystemIscType;
use App\Models\Tenant\Catalogs\UnitType;
use App\Models\Tenant\Item;
use App\CoreFacturalo\Helpers\Storage\TempImageHelper;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Requests\Tenant\ItemRequest;
use App\Http\Resources\Tenant\ItemCollection;
use App\Http\Resources\Tenant\ItemResource;
use App\Models\Tenant\User;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\ItemUnitType;
use Exception;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel;
use Modules\Account\Models\Account;
use App\Models\Tenant\ItemTag;
use App\Models\Tenant\Catalogs\Tag;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Helpers\UploadFileHelper;
use Modules\Item\Models\WebPlatform;



class ItemSetController extends Controller
{
    public function index()
    {
        return view('tenant.item_sets.index');
    }


    public function columns()
    {
        return [
            'description' => 'Nombre',
            'internal_id' => 'Código interno',
        ];
    }

    public function records(Request $request)
    {
        $records = Item::whereTypeUser()
                        ->whereIsSet()
                        ->where($request->column, 'like', "%{$request->value}%")
                        ->orderBy('description');

        return new ItemCollection($records->paginate(config('tenant.items_per_page')));
    }

    public function create()
    {
        return view('tenant.items.form');
    }

    public function tables()
    {
        $unit_types = UnitType::whereActive()->orderByDescription()->get();
        $currency_types = CurrencyType::whereActive()->orderByDescription()->get();
        $attribute_types = AttributeType::whereActive()->orderByDescription()->get();
        $system_isc_types = SystemIscType::whereActive()->orderByDescription()->get();
        $affectation_igv_types = AffectationIgvType::whereActive()->get();
        $web_platforms = WebPlatform::get();
        // $warehouses = Warehouse::all();
        // $accounts = Account::all();
        // $tags = Tag::all(); 

        return compact('unit_types', 'currency_types', 'attribute_types', 'system_isc_types', 'affectation_igv_types', 'web_platforms');
    }


    public function item_tables()
    { 

        $individual_items = Item::whereWarehouse()->whereTypeUser()->whereNotIsSet()->whereIsActive()->get()->transform(function($row) {
            $full_description = ($row->internal_id)?$row->internal_id.' - '.$row->description:$row->description;
            return [
                'id' => $row->id,
                'full_description' => $full_description,
                'internal_id' => $row->internal_id,
                'description' => $row->description,
                'sale_unit_price' => $row->sale_unit_price,
            ];
        });

        return compact('individual_items');
    }


    public function record($id)
    {
        $record = new ItemResource(Item::findOrFail($id));

        return $record;
    }

    public function store(ItemRequest $request) {

        $id = $request->input('id');
        $temp_path = $request->input('temp_path');
        $resolvedImage = $temp_path ? TempImageHelper::resolveValidated($temp_path) : null;

        $record = DB::connection('tenant')->transaction(function () use ($request, $id, $resolvedImage) {

            $item = Item::firstOrNew(['id' => $id]);
            $item->item_type_id = '01';
            $item->fill($request->all());

            if ($resolvedImage) {
                $directory = 'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR;
                $file_content = file_get_contents($resolvedImage['path']);
                $datenow = date('YmdHis');
                $slug = Str::slug($item->description) ?: 'item-set';
                $file_name = $slug.'-'.$datenow.'.'.$resolvedImage['extension'];
                if (!Storage::put($directory.$file_name, $file_content)) {
                    throw new \RuntimeException('Unable to store item set image.');
                }
                $item->image = $file_name;
            } elseif (!$request->input('image') && !$request->input('temp_path') && !$request->input('image_url')) {
                $item->image = 'imagen-no-disponible.jpg';
            }

            $item->save();

            $item->sets()->delete();

            foreach ($request->individual_items as $row) {
                
                $item->sets()->create([
                    'individual_item_id' => $row['individual_item_id'],
                    'quantity' => $row['quantity'],
                ]);

            }
            
            $item->update();

            return $item;
        });

        if ($resolvedImage) {
            TempImageHelper::deleteOwned($resolvedImage['path']);
        }

        return [
            'success' => true,
            'message' => ($id)?'Producto compuesto editado con éxito':'Producto compuesto registrado con éxito',
            'id' => $record->id
        ];

    }

    public function destroy($id)
    {
        try {

            $item = Item::findOrFail($id);
            $this->deleteRecordInitialKardex($item);
            $item->delete();

            return [
                'success' => true,
                'message' => 'Producto compuesto eliminado con éxito'
            ];

        } catch (Exception $e) {

            return ($e->getCode() == '23000') ? ['success' => false,'message' => 'El producto compuesto esta siendo usado por otros registros, no puede eliminar'] : ['success' => false,'message' => 'Error inesperado, no se pudo eliminar el producto compuesto'];

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
        $item = Item::find($request->id);
        $visible = $request->apply_store == true ? 1 : 0 ;
        $item->apply_store = $visible;
        $item->save();

        return [
            'success' => true,
            'message' => ($visible > 0 )?'El Producto ya es visible en tienda virtual' : 'El Producto ya no es visible en tienda virtual',
            'id' => $request->id
        ];

    }





}
