<?php

namespace App\Http\Controllers\Tenant;

use App\Models\Tenant\User;
use App\Models\Tenant\Module;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Establishment;
use App\Http\Requests\Tenant\UserRequest;
use App\Http\Resources\Tenant\UserResource;
use Modules\LevelAccess\Models\ModuleLevel;
use App\Http\Resources\Tenant\UserCollection;
use App\Support\Access\PermissionTree;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        return view('tenant.users.index');
    }

    public function record($id)
    {
        $record = new UserResource(User::findOrFail($id));

        return $record;
    }

    public function tables()
    {
        $modules = Module::orderBy('order_menu')->orderBy('id')->get();
        // New users start without permissions: the administrator must grant them explicitly.
        $datasource = PermissionTree::build();

        $establishments = Establishment::orderBy('description')->get();
        $types = [['type' => 'admin', 'description' => 'Administrador'], ['type' => 'seller', 'description' => 'Vendedor']];

        return compact('modules', 'establishments', 'types', 'datasource');
    }

    public function store(UserRequest $request)
    {
        $id = $request->input('id');

        if (!$id)  //VALIDAR EMAIL DISPONIBLE
        {
            $verify = User::where('email', $request->input('email'))->first();
            if ($verify) {
                return [
                    'success' => false,
                    'message' => 'Email no disponible. Ingrese otro Email'
                ];
            }
        }

        $ownerId = (int) User::query()->min('id');
        $isOwnerTarget = $id && (int) $id === $ownerId;

        // The owner always has full access; its permissions are not editable.
        // An edit that does not send the permission tree leaves the stored permissions untouched.
        $syncPermissions = !$isOwnerTarget && ($request->has('modules') || !$id);
        $moduleIds = [];
        $levelIds = [];

        if ($syncPermissions) {
            $requestedModules = collect($request->input('modules'))->where('checked', true)->pluck('id')->map(function ($value) {
                return (int) $value;
            })->unique()->values()->all();
            $moduleIds = Module::whereIn('id', $requestedModules)->pluck('id')->all();

            if (count($moduleIds) === 0) {
                throw ValidationException::withMessages(['modules' => ['Seleccione al menos un módulo.']]);
            }

            $requestedLevels = collect($request->input('levels'))->where('checked', true)->pluck('id')->map(function ($value) {
                return (int) $value;
            })->unique()->values()->all();
            // A level is only valid inside a module that is also selected.
            $levelIds = ModuleLevel::whereIn('id', $requestedLevels)->whereIn('module_id', $moduleIds)->pluck('id')->all();

            // A module that defines levels in the catalogue requires at least one selected level.
            $modulesWithCatalogLevels = ModuleLevel::query()->distinct()->pluck('module_id')->map(function ($value) {
                return (int) $value;
            })->all();
            $modulesWithSelectedLevels = ModuleLevel::whereIn('id', $levelIds)->distinct()->pluck('module_id')->map(function ($value) {
                return (int) $value;
            })->all();
            $moduleIds = array_values(array_filter($moduleIds, function ($moduleId) use ($modulesWithCatalogLevels, $modulesWithSelectedLevels) {
                return !in_array((int) $moduleId, $modulesWithCatalogLevels, true)
                    || in_array((int) $moduleId, $modulesWithSelectedLevels, true);
            }));

            if (count($moduleIds) === 0) {
                throw ValidationException::withMessages(['modules' => ['Seleccione al menos un módulo.']]);
            }
        }

        $user = User::firstOrNew(['id' => $id]);
        $user->name = $request->input('name');
        $user->email = $request->input('email');
        $user->establishment_id = $request->input('establishment_id');
        $user->type = $request->input('type');
        if (!$id) {
            $user->api_token = str_random(50);
            $user->password = bcrypt($request->input('password'));
        } elseif ($request->has('password')) {
            if (config('tenant.password_change')) {
                $user->password = bcrypt($request->input('password'));
            }
        }
        $user->save();

        if ($syncPermissions) {
            $user->modules()->sync($moduleIds);
            $user->levels()->sync($levelIds);
        }

        return [
            'success' => true,
            'message' => ($id) ? 'Usuario actualizado' : 'Usuario registrado'
        ];
    }

    public function records()
    {
        $records = User::all();

        return new UserCollection($records);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        if ($user->isOwner()) {
            return [
                'success' => false,
                'message' => 'No se puede eliminar al usuario principal'
            ];
        }
        $user->delete();

        return [
            'success' => true,
            'message' => 'Usuario eliminado con éxito'
        ];
    }
}
