<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Identity\Support\PermissionRegistry;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Permission matrix (module × action). System roles are editable but never deletable; the Super Admin role always
 * holds every permission. A non-super-admin can only grant permissions they themselves hold company-wide.
 */
class RoleEditor extends Component
{
    use InteractsWithAdmin;

    public ?Role $role = null;

    public array $form = [];

    /** @var array<string, array<string, bool>> module => action => granted (nested so wire:model paths stay valid) */
    public array $granted = [];

    public function mount(?Role $role = null): void
    {
        if ($role?->exists) {
            abort_unless($this->canGlobally('roles.view'), 403);
            $this->role = $role;
            $this->form = $role->only(['key', 'name_en', 'name_ar', 'description', 'scope']);
            $this->form['description'] = (string) $role->description;
            $held = $role->permissions()->pluck('key')->all();
        } else {
            abort_unless($this->canGlobally('roles.create'), 403);
            $this->form = ['key' => '', 'name_en' => '', 'name_ar' => '', 'description' => '', 'scope' => 'branch'];
            $held = [];
        }
        foreach (PermissionRegistry::permissions() as $p) {
            $this->granted[$p['module']][$p['action']] = in_array($p['key'], $held, true);
        }
    }

    /** @return array<string, bool> flat permission key => granted */
    private function flat(): array
    {
        $out = [];
        foreach ($this->granted as $module => $actions) {
            foreach ((array) $actions as $action => $on) {
                $out[$module.'.'.$action] = (bool) $on;
            }
        }

        return $out;
    }

    private function locked(): bool
    {
        return $this->role?->key === Role::SUPER_ADMIN;
    }

    /** Permission keys the actor may grant or revoke. */
    private function grantable(): array
    {
        $actor = auth()->user();
        $all = array_column(PermissionRegistry::permissions(), 'key');
        if ($actor->isSuperAdmin()) {
            return $all;
        }
        $acl = app(AccessControl::class);

        return array_values(array_filter($all, fn ($k) => $acl->allowsGlobally($actor, $k)));
    }

    public function toggleModule(string $module): void
    {
        $this->toggleKeys(fn ($k) => str_starts_with($k, $module.'.'));
    }

    public function toggleAction(string $action): void
    {
        $this->toggleKeys(fn ($k) => str_ends_with($k, '.'.$action));
    }

    private function toggleKeys(callable $match): void
    {
        $flat = $this->flat();
        $keys = collect(array_keys($flat))->filter($match)->intersect($this->grantable());
        $allOn = $keys->every(fn ($k) => $flat[$k]);
        foreach ($keys as $k) {
            [$m, $a] = explode('.', $k, 2);
            $this->granted[$m][$a] = ! $allOn;
        }
    }

    public function save(AuditLogger $audit)
    {
        abort_unless($this->canGlobally($this->role ? 'roles.edit' : 'roles.create'), 403);
        if ($this->locked()) {
            $this->toast(__('org_admin.super_admin_locked'), 'error');

            return;
        }
        $rules = [
            'form.name_en' => 'required|string|max:120',
            'form.name_ar' => 'required|string|max:120',
            'form.description' => 'nullable|string|max:1000',
            'granted' => 'array',
            'granted.*' => 'array',
            'granted.*.*' => 'boolean',
        ];
        if (! $this->role) {
            $rules['form.key'] = ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'key')];
            $rules['form.scope'] = 'required|in:global,branch';
        }
        $data = $this->validate($rules, [], ['form.key' => __('org_admin.role_key')])['form'];

        $wanted = array_keys(array_filter($this->flat()));
        $valid = array_column(PermissionRegistry::permissions(), 'key');
        $wanted = array_values(array_intersect($wanted, $valid));
        $before = $this->role ? $this->role->permissions()->pluck('key')->all() : [];
        $added = array_values(array_diff($wanted, $before));
        $removed = array_values(array_diff($before, $wanted));

        // Escalation guard: every changed permission must be one the actor holds company-wide.
        $outside = array_diff(array_merge($added, $removed), $this->grantable());
        if ($outside) {
            $this->toast(__('errors.cannot_grant_beyond_authority'), 'error');

            return;
        }

        $role = DB::transaction(function () use ($data, $wanted, $added, $removed, $before, $audit) {
            if ($this->role) {
                $role = $this->role;
                $role->fill(['name_en' => $data['name_en'], 'name_ar' => $data['name_ar'], 'description' => $data['description'] ?: null]);
                $dirty = $role->getDirty();
                $old = array_intersect_key($role->getOriginal(), $dirty);
                $role->save();
                if ($dirty) {
                    $audit->log('role.updated', $role, $old, $dirty);
                }
            } else {
                $role = Role::create(['key' => $data['key'], 'name_en' => $data['name_en'], 'name_ar' => $data['name_ar'],
                    'description' => $data['description'] ?: null, 'scope' => $data['scope'], 'is_system' => false]);
                $audit->log('role.created', $role, [], $role->only(['key', 'name_en', 'name_ar', 'scope']));
            }
            if ($added || $removed) {
                $role->permissions()->sync(Permission::whereIn('key', $wanted)->pluck('id'));
                $audit->log('role.permissions_updated', $role, ['permissions' => $before], ['permissions' => $wanted], ['added' => $added, 'removed' => $removed]);
            }

            return $role;
        });
        app(AccessControl::class)->forget();

        if (! $this->role) {
            session()->flash('toast', ['type' => 'success', 'message' => __('org_admin.role_created')]);

            return $this->redirectRoute('admin.roles.edit', $role, navigate: true);
        }
        $this->toast(__('org_admin.role_saved', ['added' => count($added), 'removed' => count($removed)]));
    }

    public function delete(AuditLogger $audit)
    {
        abort_unless($this->canGlobally('roles.delete'), 403);
        if (! $this->role || $this->role->is_system) {
            $this->toast(__('org_admin.system_role_not_deletable'), 'error');

            return;
        }
        if (DB::table('user_roles')->where('role_id', $this->role->id)->exists()) {
            $this->toast(__('org_admin.role_in_use'), 'error');

            return;
        }
        $snapshot = $this->role->only(['id', 'key', 'name_en', 'scope']) + ['permissions' => $this->role->permissions()->pluck('key')->all()];
        $this->role->delete();
        $audit->log('role.deleted', 'Role', $snapshot);
        app(AccessControl::class)->forget();
        session()->flash('toast', ['type' => 'success', 'message' => __('org_admin.role_deleted')]);

        return $this->redirectRoute('admin.roles.index', navigate: true);
    }

    public function render()
    {
        $grantable = array_flip($this->grantable());
        $members = $this->role
            ? UserRole::with(['user:id,name,email', 'branch:id,code'])->where('role_id', $this->role->id)->latest()->limit(12)->get()
            : collect();

        return view('livewire.admin.organization.role-editor', [
            'modules' => PermissionRegistry::MODULES,
            'actions' => PermissionRegistry::ACTIONS,
            'grantableKeys' => $grantable,
            'locked' => $this->locked(),
            'canSave' => ! $this->locked() && $this->canGlobally($this->role ? 'roles.edit' : 'roles.create'),
            'canDelete' => $this->role && ! $this->role->is_system && $this->canGlobally('roles.delete'),
            'members' => $members,
            'grantedCount' => count(array_filter($this->flat())),
        ])->title($this->role ? $this->role->localized('name') : __('org_admin.new_role'));
    }
}
