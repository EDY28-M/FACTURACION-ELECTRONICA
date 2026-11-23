<?php
namespace App\Http\Controllers\Tenant;

use App\CoreFacturalo\Helpers\Storage\TempImageHelper;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\Tenant\PromotionRequest;
use App\Http\Resources\Tenant\PromotionCollection;
use App\Http\Resources\Tenant\PromotionResource;
use Exception;
use Illuminate\Http\Request;
use App\Models\Tenant\Promotion;
use App\Models\Tenant\Item;
use Modules\Finance\Helpers\UploadFileHelper;


class PromotionController extends Controller
{
    public function index()
    {
        return view('tenant.promotion.index');
    }


    public function columns()
    {
        return [
            'description' => 'Nombre'
            // 'description' => 'Descripción'
        ];
    }

    public function tables()
    {
       
        $items = Item::where('apply_store', 1)->where('active', 1)->get();
        return compact('items');
    }


    public function records(Request $request)
    {
        $records = Promotion::orderBy('description');
        
        return new PromotionCollection($records->paginate(config('tenant.items_per_page')));
    }

    public function create()
    {
        return view('tenant.promotion.form');
    }


    public function record($id)
    {
        $record = new PromotionResource(Promotion::findOrFail($id));
        return $record;
    }

    public function store(PromotionRequest $request) {

        $id = $request->input('id');

        if(!$id)
        {
            // The slider shows at most 3 promotions (active ones only, see the Promotion global scope).
            if(Promotion::count() > 2)
            {
                return [
                    'success' => false,
                    'message' => 'Solo está permitido tener 3 promociones activas. Elimine una para crear otra.',
                ];
            }
        }

        $temp_path = $request->input('temp_path');
        $resolvedImage = $temp_path ? TempImageHelper::resolveValidated($temp_path) : null;

        // Editing an id that does not exist must fail instead of silently creating another record.
        $item = $id ? Promotion::findOrFail($id) : new Promotion();

        if (!$id && !$resolvedImage) {
            return response()->json([
                'image' => ['Suba la imagen de la promoción.'],
            ], 422);
        }

        // Only the editable fields: `image` and `status` never come straight from the request body.
        $item->fill($request->only(['name', 'description', 'item_id']));

        if ($resolvedImage) {
            $directory = 'public'.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'promotions'.DIRECTORY_SEPARATOR;
            $file_content = file_get_contents($resolvedImage['path']);
            $datenow = date('YmdHis');
            $slug = Str::slug($item->name) ?: (Str::slug($item->description) ?: 'promotion');
            $file_name = $slug.'-'.$datenow.'.'.$resolvedImage['extension'];
            if (!Storage::put($directory.$file_name, $file_content)) {
                throw new \RuntimeException('Unable to store promotion image.');
            }
            $item->image = $file_name;
        }

        $item->save();

        if ($resolvedImage) {
            TempImageHelper::deleteOwned($resolvedImage['path']);
        }

        return [
            'success' => true,
            'message' => ($id)?'Promocion editada con éxito':'Promocion registrada con éxito',
            'id' => $item->id
        ];
    }
    
    public function destroy($id)
    {
        //return 'sd';
        $item = Promotion::findOrFail($id);
        $item->status = 0;
        $item->save();

        return [
            'success' => true,
            'message' => 'Promocion eliminada con éxito'
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
        return TempImageHelper::storeTemp($request['file'], $request['type'] ?? 'promotion_');
    }


  


 



}
