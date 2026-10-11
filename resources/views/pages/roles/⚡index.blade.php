<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Enums\RecordStatus;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Roles y permisos')] class extends Component
{
    public ?int $selectedRoleId = null;

    public array $permissionIds = [];

    public string $permissionSearch = '';

    public bool $selectAllPermissions = false;

    public bool $showForm = false;

    public ?int $editingRoleId = null;

    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('roles.view');
        $this->selectedRoleId = $this->visibleRolesQuery()->orderBy('id')->value('id');
        $this->resetRoleForm();
        $this->loadRolePermissions();
    }

    public function selectRole(int $roleId): void
    {
        abort_unless($this->visibleRolesQuery()->whereKey($roleId)->exists(), 404);
        $this->selectedRoleId = $roleId;
        $this->loadRolePermissions();
    }

    public function toggleAllPermissions(): void
    {
        Gate::authorize('roles.edit');
        $this->selectAllPermissions = ! $this->selectAllPermissions;
        $this->permissionIds = $this->selectAllPermissions
            ? $this->allPermissionIds()->all()
            : [];
    }

    public function savePermissions(): void
    {
        Gate::authorize('roles.edit');
        $role = $this->visibleRolesQuery()->findOrFail($this->selectedRoleId);
        $role->permissions()->sync(
            collect($this->permissionIds)
                ->mapWithKeys(fn (int|string $permissionId): array => [
                    (int) $permissionId => ['created_at' => now()],
                ])
                ->all(),
        );

        $this->selectAllPermissions = count($this->permissionIds) === Permission::query()->count();
        Flux::toast(variant: 'success', text: 'Permisos del rol actualizados.');
    }

    public function createRole(): void
    {
        Gate::authorize('roles.create');
        $this->resetRoleForm();
        $this->showForm = true;
    }

    public function editRole(int $roleId): void
    {
        Gate::authorize('roles.edit');
        $role = $this->visibleRolesQuery()->findOrFail($roleId);
        $this->editingRoleId = $role->id;
        $this->form = [
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description ?? '',
            'status' => $role->status,
        ];
        $this->showForm = true;
    }

    public function duplicateRole(int $roleId): void
    {
        Gate::authorize('roles.create');
        $role = $this->visibleRolesQuery()->with('permissions')->findOrFail($roleId);
        $name = $role->name.' copia';
        $slug = Str::slug($name);
        $suffix = 2;

        while (Role::query()->where('slug', $slug)->exists()) {
            $slug = Str::slug($name).'-'.$suffix;
            $suffix++;
        }

        $duplicate = Role::query()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => $role->description,
            'status' => RecordStatus::Active->value,
        ]);
        $duplicate->permissions()->sync($role->permissions->pluck('id')->mapWithKeys(fn (int $id): array => [$id => ['created_at' => now()]])->all());
        $this->selectedRoleId = $duplicate->id;
        $this->loadRolePermissions();
        Flux::toast(variant: 'success', text: 'Rol duplicado correctamente.');
    }

    public function saveRole(): void
    {
        $role = $this->editingRoleId ? $this->visibleRolesQuery()->findOrFail($this->editingRoleId) : null;
        $role ? Gate::authorize('roles.edit') : Gate::authorize('roles.create');

        $validated = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('roles', 'slug')->ignore($this->editingRoleId)],
            'form.description' => ['nullable', 'string', 'max:500'],
            'form.status' => ['required', Rule::in([RecordStatus::Active->value, RecordStatus::Inactive->value])],
        ])['form'];

        if ($role instanceof Role) {
            $role->update($validated);
            $this->selectedRoleId = $role->id;
            Flux::toast(variant: 'success', text: 'Rol actualizado.');
        } else {
            $role = Role::query()->create($validated);
            $this->selectedRoleId = $role->id;
            Flux::toast(variant: 'success', text: 'Rol creado.');
        }

        $this->closeRoleForm();
        $this->loadRolePermissions();
    }

    public function deactivateRole(int $roleId): void
    {
        Gate::authorize('roles.delete');
        $role = $this->visibleRolesQuery()->findOrFail($roleId);
        abort_if(in_array($role->slug, ['administrador', 'supervisor', 'asesor'], true), 422, 'Los roles del sistema no se pueden desactivar.');
        $role->update(['status' => RecordStatus::Inactive->value]);
        Flux::toast(variant: 'success', text: 'Rol desactivado.');
    }

    public function closeRoleForm(): void
    {
        $this->showForm = false;
        $this->resetRoleForm();
        $this->resetValidation();
    }

    #[Computed]
    public function roles(): Collection
    {
        return $this->visibleRolesQuery()
            ->withCount('users')
            ->with('permissions')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function selectedRole(): ?Role
    {
        return $this->selectedRoleId
            ? $this->visibleRolesQuery()->withCount('users')->with(['permissions', 'users' => fn ($query) => $query->with('role')->orderBy('name')->limit(8)])->find($this->selectedRoleId)
            : null;
    }

    #[Computed]
    public function permissionsByModule(): Collection
    {
        return Permission::query()
            ->when($this->permissionSearch !== '', fn (Builder $query): Builder => $query->where(function (Builder $searchQuery): void {
                $term = '%'.$this->permissionSearch.'%';
                $searchQuery->where('name', 'like', $term)->orWhere('slug', 'like', $term)->orWhere('module', 'like', $term);
            }))
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module');
    }

    #[Computed]
    public function metrics(): array
    {
        $roles = $this->roles;
        $systemSlugs = ['administrador', 'supervisor', 'asesor'];

        return [
            'active' => $roles->where('status', RecordStatus::Active->value)->count(),
            'users' => $roles->sum('users_count'),
            'permissions' => Permission::query()->count(),
            'custom' => $roles->whereNotIn('slug', $systemSlugs)->count(),
        ];
    }

    #[Computed]
    public function recentRoles(): Collection
    {
        return $this->visibleRolesQuery()
            ->withCount(['users', 'permissions'])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function roleScope(): array
    {
        $user = Auth::user();

        return match (true) {
            $user->isAdministrador() => ['label' => 'Administrador', 'description' => 'Gestiona roles, permisos y configuración global.'],
            $user->isSupervisor() => ['label' => 'Supervisor', 'description' => 'Consulta los perfiles que aplican a tu equipo.'],
            default => ['label' => 'Asesor', 'description' => 'Consulta los permisos activos de tu rol.'],
        };
    }

    public function roleType(Role $role): string
    {
        return in_array($role->slug, ['administrador', 'supervisor', 'asesor'], true) ? 'Sistema' : 'Personalizado';
    }

    public function roleClass(Role $role): string
    {
        return match ($role->slug) {
            'administrador' => 'is-admin',
            'supervisor' => 'is-supervisor',
            'asesor' => 'is-advisor',
            default => 'is-custom',
        };
    }

    public function moduleMeta(string $module): array
    {
        return match (Str::lower($module)) {
            'dashboard' => ['icon' => 'bi-speedometer2', 'tone' => 'blue'],
            'leads' => ['icon' => 'bi-people', 'tone' => 'blue'],
            'pipeline' => ['icon' => 'bi-funnel', 'tone' => 'cyan'],
            'propiedades' => ['icon' => 'bi-buildings', 'tone' => 'green'],
            'seguimientos' => ['icon' => 'bi-clipboard-check', 'tone' => 'green'],
            'calendario', 'citas' => ['icon' => 'bi-calendar3', 'tone' => 'purple'],
            'reservas' => ['icon' => 'bi-bookmark-star', 'tone' => 'purple'],
            'ventas' => ['icon' => 'bi-bar-chart', 'tone' => 'green'],
            'comisiones' => ['icon' => 'bi-percent', 'tone' => 'purple'],
            'reportes' => ['icon' => 'bi-file-earmark-bar-graph', 'tone' => 'blue'],
            'usuarios' => ['icon' => 'bi-person-gear', 'tone' => 'blue'],
            'roles' => ['icon' => 'bi-key', 'tone' => 'cyan'],
            'permisos' => ['icon' => 'bi-shield-check', 'tone' => 'red'],
            'configuracion' => ['icon' => 'bi-gear', 'tone' => 'slate'],
            'notificaciones' => ['icon' => 'bi-bell', 'tone' => 'purple'],
            default => ['icon' => 'bi-grid', 'tone' => 'slate'],
        };
    }

    private function allPermissionIds(): Collection
    {
        return Permission::query()->pluck('id')->map(fn (int $id): string => (string) $id);
    }

    private function resetRoleForm(): void
    {
        $this->editingRoleId = null;
        $this->form = [
            'name' => '',
            'slug' => '',
            'description' => '',
            'status' => RecordStatus::Active->value,
        ];
    }

    private function loadRolePermissions(): void
    {
        $this->permissionIds = $this->selectedRoleId
            ? $this->visibleRolesQuery()->findOrFail($this->selectedRoleId)->permissions()->pluck('permissions.id')->map(fn (int $id): string => (string) $id)->all()
            : [];
        $this->selectAllPermissions = count($this->permissionIds) === Permission::query()->count();
    }

    private function visibleRolesQuery(): Builder
    {
        $user = Auth::user();
        $query = Role::query();

        if ($user->isAdministrador()) {
            return $query;
        }

        if ($user->isSupervisor()) {
            return $query->whereIn('slug', ['supervisor', 'asesor']);
        }

        return $query->where('slug', $user->roleSlug() ?? 'asesor');
    }
};
?>

<section class="crm-roles-page">
    <header class="crm-card crm-roles-header">
        <div><p class="crm-eyebrow">Administración</p><h1 class="crm-module-title">Roles y permisos</h1><p class="crm-module-description">Configura perfiles de acceso, privilegios y alcance de cada rol dentro del CRM.</p><p class="crm-roles-scope"><i class="bi bi-shield-check"></i>{{ $this->roleScope['label'] }} · {{ $this->roleScope['description'] }}</p></div>
        <div class="crm-roles-header-actions">@can('roles.create')<button type="button" class="crm-roles-primary-button" wire:click="createRole"><i class="bi bi-plus-lg"></i> Nuevo rol</button>@endcan<a href="{{ route('permissions.index') }}" class="crm-roles-secondary-button" wire:navigate><i class="bi bi-book"></i> Catálogo de permisos</a></div>
    </header>

    <section class="crm-roles-metrics"><article class="crm-card crm-roles-metric is-blue"><span><i class="bi bi-people"></i></span><strong>{{ $this->metrics['active'] }}</strong><small>Roles activos</small><em>de {{ $this->roles->count() }} roles totales</em></article><article class="crm-card crm-roles-metric is-green"><span><i class="bi bi-person-check"></i></span><strong>{{ $this->metrics['users'] }}</strong><small>Usuarios asignados</small><em>en todos los roles visibles</em></article><article class="crm-card crm-roles-metric is-amber"><span><i class="bi bi-key"></i></span><strong>{{ $this->metrics['permissions'] }}</strong><small>Permisos disponibles</small><em>en el sistema</em></article><article class="crm-card crm-roles-metric is-purple"><span><i class="bi bi-layers"></i></span><strong>{{ $this->metrics['custom'] }}</strong><small>Roles personalizados</small><em>creados por tu equipo</em></article></section>

    <section class="crm-card crm-roles-available"><div class="crm-roles-section-heading"><div><p class="crm-eyebrow">Roles disponibles</p><h2>Perfiles de acceso</h2></div><span>{{ $this->roles->count() }} roles <i class="bi bi-arrow-right"></i></span></div><div class="crm-roles-cards">@foreach ($this->roles as $role)<article class="crm-role-card {{ $selectedRoleId === $role->id ? 'is-selected' : '' }}" wire:key="role-card-{{ $role->id }}"><div class="crm-role-card-top"><span class="crm-role-card-icon {{ $this->roleClass($role) }}"><i class="bi {{ $role->slug === 'administrador' ? 'bi-crown-fill' : ($role->slug === 'supervisor' ? 'bi-people-fill' : ($role->slug === 'asesor' ? 'bi-person-fill' : 'bi-megaphone-fill')) }}"></i></span><span class="crm-role-type {{ $this->roleClass($role) }}">{{ $this->roleType($role) }}</span></div><button type="button" class="crm-role-card-select" wire:click="selectRole({{ $role->id }})"><h3>{{ $role->name }}</h3><div><span><i class="bi bi-people"></i>{{ $role->users_count }} usuarios</span><span><i class="bi bi-key"></i>{{ $role->permissions->count() }} permisos</span></div><p>{{ $role->description ?: 'Perfil de acceso configurado para la operación inmobiliaria.' }}</p>@if ($selectedRoleId === $role->id)<strong class="crm-role-selected"><i class="bi bi-check-circle-fill"></i> Rol seleccionado</strong>@else<button type="button" class="crm-role-select-button" wire:click.stop="selectRole({{ $role->id }})">Seleccionar</button>@endif</button><button type="button" class="crm-role-card-menu" wire:click="selectRole({{ $role->id }})" title="Ver detalle"><i class="bi bi-three-dots-vertical"></i></button></article>@endforeach</div></section>

    <div class="crm-roles-detail-layout">
        <section class="crm-card crm-role-detail-card">@if ($this->selectedRole)<div class="crm-role-detail-heading"><div><p class="crm-eyebrow">Detalle del rol</p><div class="crm-role-detail-title"><span class="crm-role-card-icon {{ $this->roleClass($this->selectedRole) }}"><i class="bi {{ $this->selectedRole->slug === 'administrador' ? 'bi-crown-fill' : 'bi-person-fill' }}"></i></span><div><h2>{{ $this->selectedRole->name }}</h2><span>{{ $this->roleType($this->selectedRole) }}</span></div></div></div><button type="button" class="crm-roles-icon-button"><i class="bi bi-three-dots"></i></button></div><dl class="crm-role-detail-list"><div><dt>Estado</dt><dd><i class="bi bi-circle-fill is-active"></i>{{ $this->selectedRole->status === RecordStatus::Active->value ? 'Activo' : 'Inactivo' }}</dd></div><div><dt>Usuarios asignados</dt><dd><i class="bi bi-people"></i>{{ $this->selectedRole->users_count ?? $this->selectedRole->users->count() }} usuarios</dd></div><div><dt>Descripción</dt><dd>{{ $this->selectedRole->description ?: 'Sin descripción definida.' }}</dd></div><div><dt>Alcance</dt><dd><i class="bi bi-diagram-3"></i>{{ $this->selectedRole->slug === 'administrador' ? 'Toda la organización' : ($this->selectedRole->slug === 'supervisor' ? 'Equipo asignado' : 'Información asignada') }}</dd></div></dl><div class="crm-role-tags"><span>Acceso operativo</span><span>{{ $this->roleType($this->selectedRole) }}</span><span class="is-critical">{{ $this->selectedRole->slug === 'administrador' ? 'Crítico' : 'Controlado' }}</span></div><div class="crm-role-users-heading"><strong>Usuarios asignados ({{ $this->selectedRole->users_count ?? $this->selectedRole->users->count() }})</strong><a href="{{ route('users.index') }}" wire:navigate>Ver todos <i class="bi bi-arrow-right"></i></a></div><div class="crm-role-users">@forelse ($this->selectedRole->users as $roleUser)<div><span class="crm-role-user-avatar {{ $this->roleClass($this->selectedRole) }}">{{ $roleUser->initials() }}</span><span><strong>{{ $roleUser->name }}</strong><small>{{ $roleUser->email }}</small></span>@if ($roleUser->id === Auth::id())<em>Tú</em>@endif</div>@empty<p>No hay usuarios asignados.</p>@endforelse</div><div class="crm-role-actions">@can('roles.create')<button type="button" wire:click="duplicateRole({{ $this->selectedRole->id }})"><i class="bi bi-copy"></i> Duplicar rol</button>@endcan @can('roles.edit')<button type="button" wire:click="editRole({{ $this->selectedRole->id }})"><i class="bi bi-pencil"></i> Editar rol</button>@endcan @can('roles.delete')@if (! in_array($this->selectedRole->slug, ['administrador', 'supervisor', 'asesor'], true))<button type="button" class="is-danger" wire:click="deactivateRole({{ $this->selectedRole->id }})" wire:confirm="¿Desactivar este rol?"><i class="bi bi-trash"></i> Desactivar rol</button>@endif @endcan</div>@else<div class="crm-empty">No hay roles configurados.</div>@endif</section>

        <section class="crm-card crm-role-permissions-card"><div class="crm-role-permissions-heading"><div><h2>Permisos del rol</h2><p>Activa o desactiva los permisos que hereda este perfil.</p></div><label><input type="checkbox" wire:click="toggleAllPermissions" @checked($selectAllPermissions) @disabled(! Gate::allows('roles.edit'))> Seleccionar todo</label></div><div class="crm-role-permissions-search"><i class="bi bi-search"></i><input type="search" wire:model.live.debounce.350ms="permissionSearch" placeholder="Buscar módulos o permisos..."></div><div class="crm-role-permissions-list">@foreach ($this->permissionsByModule as $module => $permissions) @php($moduleMeta = $this->moduleMeta($module))<section class="crm-permission-module" wire:key="permission-module-{{ Str::slug($module) }}"><div class="crm-permission-module-heading"><span class="crm-permission-module-icon is-{{ $moduleMeta['tone'] }}"><i class="bi {{ $moduleMeta['icon'] }}"></i></span><div><strong>{{ $module }}</strong><small>{{ $permissions->count() }} permisos</small></div><i class="bi bi-chevron-down"></i></div><div class="crm-permission-items">@foreach ($permissions as $permission)<label wire:key="permission-{{ $permission->id }}"><input type="checkbox" value="{{ $permission->id }}" wire:model="permissionIds" @disabled(! Gate::allows('roles.edit'))><span>{{ $permission->name }}</span><small>{{ $permission->slug }}</small></label>@endforeach</div></section>@endforeach</div>@can('roles.edit')<button type="button" class="crm-roles-save-button" wire:click="savePermissions"><i class="bi bi-check2"></i> Guardar permisos</button>@endcan</section>
    </div>

    <section class="crm-card crm-roles-activity"><div class="crm-roles-section-heading"><div><p class="crm-eyebrow">Actividad reciente</p><h2>Últimos cambios en roles y asignaciones</h2></div><a href="{{ route('permissions.index') }}" wire:navigate>Ver catálogo completo <i class="bi bi-arrow-right"></i></a></div><div class="crm-roles-activity-table"><div class="crm-roles-activity-row is-heading"><span>ROL</span><span>ESTADO</span><span>USUARIOS</span><span>PERMISOS</span><span>ACTUALIZADO</span><span></span></div>@forelse ($this->recentRoles as $role)<div class="crm-roles-activity-row" wire:key="role-activity-{{ $role->id }}"><span><strong>{{ $role->name }}</strong><small>{{ $this->roleType($role) }}</small></span><span><em class="{{ $role->status === RecordStatus::Active->value ? 'is-active' : 'is-inactive' }}">{{ $role->status === RecordStatus::Active->value ? 'Activo' : 'Inactivo' }}</em></span><span>{{ $role->users_count }}</span><span>{{ $role->permissions()->count() }}</span><span>{{ $role->updated_at?->diffForHumans() }}</span><span><button type="button" wire:click="selectRole({{ $role->id }})"><i class="bi bi-three-dots-vertical"></i></button></span></div>@empty<div class="crm-empty">No hay actividad registrada.</div>@endforelse</div></section>

    @if ($showForm)<div class="crm-roles-modal-layer"><div class="crm-roles-modal-backdrop" wire:click="closeRoleForm"></div><section class="crm-card crm-roles-form-modal" role="dialog" aria-modal="true"><div class="crm-roles-form-heading"><div><p class="crm-eyebrow">Administración de accesos</p><h2>{{ $editingRoleId ? 'Editar rol' : 'Nuevo rol' }}</h2><p>Define el perfil y después asigna los permisos desde el panel.</p></div><button type="button" wire:click="closeRoleForm" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button></div><form wire:submit="saveRole"><div class="crm-roles-form-grid"><label><span>Nombre del rol <em>*</em></span><input type="text" wire:model="form.name" required placeholder="Ej. Marketing">@error('form.name')<small>{{ $message }}</small>@enderror</label><label><span>Identificador <em>*</em></span><input type="text" wire:model="form.slug" required placeholder="marketing">@error('form.slug')<small>{{ $message }}</small>@enderror</label><label class="span-two"><span>Descripción</span><textarea wire:model="form.description" rows="3" placeholder="Describe el alcance de este rol"></textarea></label><label><span>Estado</span><select wire:model="form.status"><option value="active">Activo</option><option value="inactive">Inactivo</option></select></label></div><div class="crm-roles-form-actions"><button type="button" class="crm-roles-secondary-button" wire:click="closeRoleForm">Cancelar</button><button type="submit" class="crm-roles-primary-button"><i class="bi bi-check2"></i> {{ $editingRoleId ? 'Guardar cambios' : 'Crear rol' }}</button></div></form></section></div>@endif
</section>
