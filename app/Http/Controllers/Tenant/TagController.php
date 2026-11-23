<?php
namespace App\Http\Controllers\Tenant;

use App\Imports\ItemsImport;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Requests\Tenant\TagRequest;
use App\Http\Resources\Tenant\TagCollection;
use App\Http\Resources\Tenant\TagResource;
use Exception;
use Illuminate\Http\Request;
use App\Models\Tenant\ItemTag;
use App\Models\Tenant\Catalogs\Tag;



class TagController extends Controller
{
    public function index()
    {
        return view('tenant.tags.index');
    }


    public function columns()
    {
        return [
            'name' => 'Nombre',
            'description' => 'Descripción',
        ];
    }

    public function records(Request $request)
    {
        $column = array_key_exists((string) $request->column, $this->columns()) ? $request->column : 'name';
        $records = Tag::where($column, 'like', "%{$request->value}%")->orderBy('name');
        
        return new TagCollection($records->paginate(config('tenant.items_per_page')));
    }

    public function create()
    {
        return view('tenant.items.form');
    }


    public function record($id)
    {
        $record = new TagResource(Tag::findOrFail($id));

        return $record;
    }

    public function store(TagRequest $request) {
        $id = $request->input('id');
        // Editing an unknown (or already removed) id must fail instead of creating another category.
        $person = $id ? Tag::findOrFail($id) : new Tag();
        // `status` is never taken from the request: only destroy() deactivates a category.
        $person->fill($request->only(['name', 'description']));
        $person->save();

        return [
            'success' => true,
            'message' => ($id)?'Tag editado con éxito':'Tag registrado con éxito',
            'id' => $person->id
        ];
    }
    
    public function destroy($id)
    {
        //return 'sd';
        $item = Tag::findOrFail($id);

        // A tag still assigned to products cannot be removed: the products would keep a dangling reference.
        $inUse = ItemTag::where('tag_id', $item->id)->count();
        if ($inUse > 0) {
            return [
                'success' => false,
                'message' => "No se puede eliminar la categoría porque está asignada a {$inUse} producto(s). Quítela de los productos primero.",
            ];
        }
       // $this->deleteRecordInitialKardex($item);
        $item->status = 0;
        $item->save();

       // $item->delete();

        return [
            'success' => true,
            'message' => 'Tag eliminado con éxito'
        ];
    }



  


 



}