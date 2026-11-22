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
use App\Support\Access\TenantPlan;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        return view('tenant.users.index');
    }

    public function record($id)
    {
        // A non-administrator may only open its own account.
        if (!$this->actorIsAdmin() && (int) $id !== (int) auth()->id()) {
            abort(403, 'Solo un administrador puede ver otros usuarios.');
        }

        $record = new UserResource(User::findOrFail($id));

        return $record;
    }

    public function tables()
    {
        // Only what the tenant plan includes can be granted.
        $modules = Module::whereIn('id', TenantPlan::current()->moduleIds())->orderBy('order_menu')->orderBy('id')->get();
        // New users start without permissions: the administrator must grant them explicitly.
        $datasource = PermissionTree::build();

        $establishments = Establishment::orderBy('description')->get();
        $types = [['type' => 'admin', 'description' => 'Administrador'], ['type' => 'seller', 'description' => 'Vendedor']];

        return compact('modules', 'establishments', 'types', 'datasource');
    }

    public function store(UserRequest $request)
    {
        if (!$this->actorIsAdmin()) {
            return $this->adminOnlyResponse();
        }

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

        // Only the owner can change the owner's own account (email, name, password).
        if ($isOwnerTarget && (int) auth()->id() !== $ownerId) {
            return [
                'success' => false,
                'message' => 'Solo el usuario principal puede modificar sus propios datos.',
            ];
        }

        // The owner always has full access; its permissions are not editable.
        // An edit that does not send the permission tree leaves the stored permissions untouched.
        $syncPermissions = !$isOwnerTarget && ($request->has('modules') || !$id);
        $moduleIds = [];
        $levelIds = [];

        if ($syncPermissions) {
            $requestedModules = collect($request->input('modules'))->where('checked', true)->pluck('id')->map(function ($value) {
                return (int) $value;
            })->unique()->values()->all();
            // Anything outside the tenant plan is silently dropped.
            $plan = TenantPlan::current();
            $moduleIds = Module::whereIn('id', $requestedModules)->whereIn('id', $plan->moduleIds())->pluck('id')->all();

            if (count($moduleIds) === 0) {
                throw ValidationException::withMessages(['modules' => ['Seleccione al menos un módulo.']]);
            }

            $requestedLevels = collect($request->input('levels'))->where('checked', true)->pluck('id')->map(function ($value) {
                return (int) $value;
            })->unique()->values()->all();
            // A level is only valid inside a module that is also selected.
            $levelIds = ModuleLevel::whereIn('id', $requestedLevels)
                ->whereIn('id', $plan->levelIds())
                ->whereIn('module_id', $moduleIds)
                ->pluck('id')->all();

            // A module that defines levels in the catalogue requires at least one selected level.
            $modulesWithCatalogLevels = ModuleLevel::whereIn('id', $plan->levelIds())->distinct()->pluck('module_id')->map(function ($value) {
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
        if (!$isOwnerTarget) {
            // The owner always stays an administrator.
            $user->type = $request->input('type');
        }
        if (!$id) {
            $user->api_token = str_random(50);
            $user->password = bcrypt($request->input('password'));
        } elseif ($request->filled('password')) {
            if (config('tenant.password_change')) {
                $user->password = bcrypt($request->input('password'));
            }
        }
        $user->save();

        if ($syncPermissions) {
            // Grants outside the current plan are hidden, not revoked: keep them so the user
            // gets them back if the system admin re-enables the module.
            $plan = TenantPlan::current();
            $keptModuleIds = $user->modules()->pluck('modules.id')->map('intval')
                ->reject(function ($id) use ($plan) {
                    return in_array($id, $plan->moduleIds(), true);
                })->all();
            $keptLevelIds = $user->levels()->pluck('module_levels.id')->map('intval')
                ->reject(function ($id) use ($plan) {
                    return in_array($id, $plan->levelIds(), true);
                })->all();

            $user->modules()->sync(array_values(array_unique(array_merge($moduleIds, $keptModuleIds))));
            $user->levels()->sync(array_values(array_unique(array_merge($levelIds, $keptLevelIds))));
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
        if (!$this->actorIsAdmin()) {
            return $this->adminOnlyResponse();
        }

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

    /**
     * Creating, editing and deleting users is an administrator task; the "establishments"
     * module alone is not enough (sellers may need branches and series but must not manage accounts).
     */
    private function actorIsAdmin(): bool
    {
        $actor = auth()->user();

        return $actor && ($actor->type === 'admin' || $actor->isOwner());
    }

    private function adminOnlyResponse(): array
    {
        return [
            'success' => false,
            'message' => 'Solo un administrador puede gestionar usuarios.',
        ];
    }
}
