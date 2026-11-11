<?php

namespace App\Livewire\Admin\Organization;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Support\PermissionRegistry;
use App\Livewire\Admin\Concerns\WithAdminTable;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Roles are company-level configuration: only staff holding roles.* company-wide may see or change them. */
class RoleIndex extends Component
{
    use WithAdminTable;

    #[Url(except: '')]
    public string $filterScope = '';

    public function mount(): void
    {
        abort_unless($this->canGlobally('roles.view'), 403);
    }

    public function render()
    {
        $s = $this->search;
        $roles = Role::query()->withCount(['permissions'])
            ->selectSub(fn ($q) => $q->from('user_roles')->selectRaw('count(distinct user_id)')->whereColumn('user_roles.role_id', 'roles.id'), 'members_count')
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('name_en', 'ilike', "%{$s}%")->orWhere('name_ar', 'like', "%{$s}%")->orWhere('key', 'ilike', "%{$s}%")))
            ->when($this->filterScope, fn ($q, $sc) => $q->where('scope', $sc))
            ->orderByDesc('is_system')->orderBy('name_en');

        return view('livewire.admin.organization.roles', [
            'roles' => $roles->paginate($this->perPage),
            'totalPermissions' => count(PermissionRegistry::permissions()),
            'canCreate' => $this->canGlobally('roles.create'),
        ])->title(__('nav.roles'));
    }
}
