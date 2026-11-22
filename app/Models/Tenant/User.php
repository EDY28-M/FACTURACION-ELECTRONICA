<?php

namespace App\Models\Tenant;

use App\Notifications\Tenant\PasswordResetNotification;
use App\Support\Access\TenantPlan;
use Hyn\Tenancy\Traits\UsesTenantConnection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Modules\LevelAccess\Models\ModuleLevel;
use Modules\Sale\Models\UserCommission;


class User extends Authenticatable
{
    use Notifiable, UsesTenantConnection;

    protected $with = ['establishment'];
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password', 'establishment_id','type','locked', 'identity_document_type_id', 'number', 'address', 'telephone'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token', 'api_token',
    ];

    public function modules()
    {
        return $this->belongsToMany(Module::class);
    }

    public function levels()
    {
        return $this->belongsToMany(ModuleLevel::class);
    }

    /** @var bool|null */
    private $ownerCache;

    /** @var int[]|null */
    private $moduleIdsCache;

    /** @var string[]|null */
    private $moduleValuesCache;

    /** @var int[]|null */
    private $levelIdsCache;

    /** @var string[]|null */
    private $levelValuesCache;

    /**
     * The tenant owner is the first user created for the tenant. Its modules/levels ARE the tenant
     * plan (see TenantPlan); it is not exempt from the plan, only protected from deletion/edition.
     */
    public function isOwner(): bool
    {
        if ($this->ownerCache === null) {
            // TenantPlan memoizes the owner id per request, so listing users does not query it per row.
            $this->ownerCache = (int) $this->id === (int) TenantPlan::current()->ownerId();
        }

        return $this->ownerCache;
    }

    /**
     * Masked API token for listings: only the last 4 characters are shown.
     */
    public function apiTokenHint(): ?string
    {
        $token = (string) $this->api_token;

        return $token === '' ? null : '••••••••'.substr($token, -4);
    }

    /**
     * The API token acts as this user's credential, so the full value is only shown to the user
     * itself, to the owner, or to an administrator looking at a non-owner account.
     */
    public function apiTokenVisibleTo(?User $viewer): bool
    {
        if (!$viewer) {
            return false;
        }

        if ((int) $viewer->id === (int) $this->id || $viewer->isOwner()) {
            return true;
        }

        return $viewer->type === 'admin' && !$this->isOwner();
    }

    /**
     * Effective module ids: the plan for the owner, own grants capped by the plan for everyone else.
     *
     * @return int[]
     */
    public function moduleIds(): array
    {
        if ($this->moduleIdsCache === null) {
            $plan = TenantPlan::current()->moduleIds();

            if ($this->isOwner()) {
                $this->moduleIdsCache = $plan;
            } else {
                $own = $this->modules()->pluck('modules.id')->map('intval')->all();
                $this->moduleIdsCache = array_values(array_intersect($own, $plan));
            }
        }

        return $this->moduleIdsCache;
    }

    /**
     * Effective module values (modules.value). Empty means no access.
     *
     * @return string[]
     */
    public function moduleValues(): array
    {
        if ($this->moduleValuesCache === null) {
            $ids = $this->moduleIds();
            $this->moduleValuesCache = $ids
                ? Module::whereIn('id', $ids)->orderBy('id')->pluck('value')->all()
                : [];
        }

        return $this->moduleValuesCache;
    }

    /**
     * Effective level ids: plan levels for the owner; own levels capped by the plan and by the
     * user's effective modules for everyone else.
     *
     * @return int[]
     */
    public function levelIds(): array
    {
        if ($this->levelIdsCache === null) {
            $plan = TenantPlan::current();

            if ($this->isOwner()) {
                $this->levelIdsCache = $plan->levelIds();
            } else {
                $own = $this->levels()->pluck('module_levels.id')->map('intval')->all();
                $moduleIds = $this->moduleIds();
                $this->levelIdsCache = array_values(array_filter(
                    array_intersect($own, $plan->levelIds()),
                    function ($levelId) use ($plan, $moduleIds) {
                        return in_array($plan->moduleOfLevel((int) $levelId), $moduleIds, true);
                    }
                ));
            }
        }

        return $this->levelIdsCache;
    }

    /**
     * Effective level values (module_levels.value). Empty means no access.
     *
     * @return string[]
     */
    public function levelValues(): array
    {
        if ($this->levelValuesCache === null) {
            $ids = $this->levelIds();
            $this->levelValuesCache = $ids
                ? ModuleLevel::whereIn('id', $ids)->orderBy('id')->pluck('value')->all()
                : [];
        }

        return $this->levelValuesCache;
    }

    public function authorizeModules($modules)
    {
        if ($this->hasAnyModule($modules)) {
            return true;
        }
        abort(401, 'Esta acción no está autorizada.');
    }

    public function hasAnyModule($modules)
    {
        foreach ((array) $modules as $module) {
            if ($this->hasModule($module)) {
                return true;
            }
        }

        return false;
    }

    public function hasModule($module)
    {
        return in_array($module, $this->moduleValues(), true);
    }

    /**
     * Value of the first effective module (by id), or null.
     */
    public function getModule()
    {
        $ids = $this->moduleIds();
        if (!$ids) {
            return null;
        }
        $module = Module::whereIn('id', $ids)->orderBy('id')->first();

        return $module ? $module->value : null;
    }

    public function getModules()
    {
        return Module::whereIn('id', $this->moduleIds())->get();
    }

    public function searchModule($module)
    {
        return $this->hasModule($module);
    }


    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }


    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function sale_notes()
    {
        return $this->hasMany(SaleNote::class);
    }

    public function scopeWhereTypeUser($query)
    {
        $user = auth()->user();
        return ($user->type == 'seller') ? $query->where('id', $user->id) : null;
    }



    public function getLevel()
    {
        $ids = $this->levelIds();
        if (!$ids) {
            return null;
        }
        $level = ModuleLevel::whereIn('id', $ids)->orderBy('id')->first();

        return $level ? $level->value : null;
    }

    public function getLevels()
    {
        return ModuleLevel::whereIn('id', $this->levelIds())->get();
    }

    public function searchLevel($Level)
    {
        return in_array($Level, $this->levelValues(), true);
    }

    public function user_commission()
    {
        return $this->hasOne(UserCommission::class);
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new PasswordResetNotification($token));
    }
}
