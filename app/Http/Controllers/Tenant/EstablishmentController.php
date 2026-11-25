<?php
namespace App\Http\Controllers\Tenant;

use App\Models\Tenant\Catalogs\Country;
use App\Models\Tenant\Catalogs\Department;
use App\Models\Tenant\Catalogs\District;
use App\Models\Tenant\Catalogs\Province;
use App\Models\Tenant\Establishment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\EstablishmentRequest;
use App\Http\Resources\Tenant\EstablishmentResource;
use App\Http\Resources\Tenant\EstablishmentCollection;
use App\Models\Tenant\Warehouse;
use App\Models\Tenant\Person;
use Illuminate\Support\Facades\DB;

class EstablishmentController extends Controller
{
    public function index()
    {
        return view('tenant.establishments.index');
    }

    public function create()
    {
        return view('tenant.establishments.form');
    }

    public function tables()
    {
        $countries = Country::whereActive()->orderByDescription()->get();
        $departments = Department::without(['provinces'])->whereActive()->orderByDescription()->get();
        $provinces = Province::without(['districts'])->whereActive()->orderByDescription()->get();
        $districts = District::whereActive()->orderByDescription()->get();

        $customers = Person::without(['identity_document_type', 'country', 'department', 'province', 'district'])
            ->whereType('customers')
            ->orderBy('name')
            ->take(1)
            ->get()
            ->transform(function($row) {
                return [
                    'id' => $row->id,
                    'description' => $row->number.' - '.$row->name,
                    'name' => $row->name,
                    'number' => $row->number,
                    'identity_document_type_id' => $row->identity_document_type_id,
                ];
            });

        return compact('countries', 'departments', 'provinces', 'districts', 'customers');
    }

    public function record($id)
    {
        $record = new EstablishmentResource(Establishment::findOrFail($id));

        return $record;
    }

    public function store(EstablishmentRequest $request)
    {
        $id = $request->input('id');

        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $request->validate(['file' => 'mimes:jpeg,png,jpg|max:1024']);
            $file = $request->file('file');
            $ext = $file->extension();
            $filename = time() . '.' . $ext;
            $file->storeAs('public/uploads/logos', $filename);
            $path = 'storage/uploads/logos/' . $filename;
            $request->merge(['logo' => $path]);
        }

        DB::connection('tenant')->transaction(function () use ($request, $id) {
            $establishment = Establishment::firstOrNew(['id' => $id]);
            $establishment->fill($request->all());
            $establishment->save();

            if (!$id) {
                $warehouse = new Warehouse();
                $warehouse->establishment_id = $establishment->id;
                $warehouse->description = 'Almacén - '.$establishment->description;
                $warehouse->save();
            }
        });

        return [
            'success' => true,
            'message' => ($id)?'Establecimiento actualizado':'Establecimiento registrado'
        ];
    }

    public function records()
    {
        $records = Establishment::without(['department', 'province'])
            ->with([
                'department' => function ($query) {
                    $query->without(['provinces']);
                },
                'province' => function ($query) {
                    $query->without(['districts']);
                },
            ])
            ->get();

        return new EstablishmentCollection($records);
    }

    public function destroy($id)
    {
        $establishment = Establishment::findOrFail($id);
        $establishment->delete();

        return [
            'success' => true,
            'message' => 'Establecimiento eliminado con éxito'
        ];
    }
}
