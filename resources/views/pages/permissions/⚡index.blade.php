<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Permisos')] class extends Component
{
    public string $search = '';

    public string $moduleFilter = '';

    public string $typeFilter = '';

    public string $roleFilter = '';

    public string $statusFilter = 'active';

    public ?int $selectedPermissionId = null;

    public bool $showForm = false;

    public ?int $editingPermissionId = null;

    /**
     * @var array{name: string, slug: string, description: string, module: string}
     */
    public array $form = [
        'name' => '',
        'slug' => '',
        'description' => '',
        'module' => '',
    ];

    public function mount(): void
    {
        Gate::authorize('permissions.view');

        $this->selectedPermissionId = $this->allPermissions->first()?->getKey();
    }

    public function selectPermission(int $permissionId): void
    {
        if ($this->filteredPermissions->contains('id', $permissionId)) {
            $this->selectedPermissionId = $permissionId;
        }
    }

    public function updatedSearch(): void
    {
        $this->keepSelectionVisible();
    }

    public function updatedModuleFilter(): void
    {
        $this->keepSelectionVisible();
    }

    public function updatedTypeFilter(): void
    {
        $this->keepSelectionVisible();
    }

    public function updatedRoleFilter(): void
    {
        $this->keepSelectionVisible();
    }

    public function updatedStatusFilter(): void
    {
        $this->keepSelectionVisible();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'moduleFilter', 'typeFilter', 'roleFilter']);
        $this->statusFilter = 'active';
        $this->keepSelectionVisible();
    }

    public function createPermission(): void
    {
        $this->assertCanManagePermissions();
        $this->resetPermissionForm();
        $this->showForm = true;
    }

    public function editPermission(int $permissionId): void
    {
        $this->assertCanManagePermissions();

        $permission = Permission::query()->findOrFail($permissionId);

        $this->editingPermissionId = $permission->getKey();
        $this->form = [
            'name' => $permission->name,
            'slug' => $permission->slug,
            'description' => $permission->description ?? '',
            'module' => $permission->module,
        ];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function savePermission(): void
    {
        $this->assertCanManagePermissions();

        $validated = $this->validate([
            'form.name' => ['required', 'string', 'max:140'],
            'form.slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
                Rule::unique('permissions', 'slug')->ignore($this->editingPermissionId),
            ],
            'form.description' => ['nullable', 'string', 'max:500'],
            'form.module' => ['required', 'string', 'max:80'],
        ]);

        $permission = $this->editingPermissionId
            ? Permission::query()->findOrFail($this->editingPermissionId)
            : new Permission;

        $permission->fill([
            'name' => $validated['form']['name'],
            'slug' => $validated['form']['slug'],
            'description' => $validated['form']['description'] ?: null,
            'module' => $validated['form']['module'],
        ]);
        $permission->save();

        $selectedId = $permission->getKey();
        $wasEditing = $this->editingPermissionId !== null;
        $this->selectedPermissionId = $selectedId;
        $this->showForm = false;
        $this->resetPermissionForm();
        session()->flash('permission-status', $wasEditing ? 'Permiso actualizado correctamente.' : 'Permiso creado correctamente.');
    }

    public function deletePermission(int $permissionId): void
    {
        $this->assertCanManagePermissions();

        $permission = Permission::query()->withCount('roles')->findOrFail($permissionId);

        if ($permission->roles_count > 0) {
            session()->flash('permission-error', 'No puedes eliminar un permiso que todavía está vinculado a un rol.');

            return;
        }

        $permission->delete();
        $this->selectedPermissionId = $this->filteredPermissions->first()?->getKey();
        session()->flash('permission-status', 'Permiso eliminado correctamente.');
    }

    public function closePermissionForm(): void
    {
        $this->showForm = false;
        $this->resetPermissionForm();
    }

    public function exportPermissions(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        Gate::authorize('permissions.view');

        $permissions = $this->filteredPermissions;

        return response()->streamDownload(function () use ($permissions): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Nombre', 'Slug', 'Módulo', 'Tipo', 'Roles vinculados', 'Estado']);

            foreach ($permissions as $permission) {
                fputcsv($handle, [
                    $permission->name,
                    $permission->slug,
                    $permission->module,
                    $this->permissionType($permission)['label'],
                    $permission->roles_count,
                    'Activo',
                ]);
            }

            fclose($handle);
        }, 'permisos-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    #[Computed]
    public function allPermissions(): Collection
    {
        $query = Permission::query()
            ->withCount('roles')
            ->with(['roles' => fn ($query) => $query->select('roles.id', 'roles.name', 'roles.slug', 'roles.status')])
            ->orderBy('module')
            ->orderBy('name');

        if (! $this->canManagePermissions()) {
            $query->whereHas('roles', fn ($roleQuery) => $roleQuery->whereKey(Auth::user()?->role_id));
        }

        return $query->get();
    }

    #[Computed]
    public function filteredPermissions(): Collection
    {
        $search = Str::lower(trim($this->search));

        return $this->allPermissions
            ->filter(function (Permission $permission) use ($search): bool {
                if ($search !== '' && ! Str::contains(Str::lower(implode(' ', [$permission->name, $permission->slug, $permission->module, $permission->description])), $search)) {
                    return false;
                }

                if ($this->moduleFilter !== '' && $permission->module !== $this->moduleFilter) {
                    return false;
                }

                if ($this->typeFilter !== '' && $this->permissionType($permission)['value'] !== $this->typeFilter) {
                    return false;
                }

                if ($this->roleFilter !== '' && ! $permission->roles->contains('id', (int) $this->roleFilter)) {
                    return false;
                }

                return $this->statusFilter !== 'inactive';
            })
            ->values();
    }

    #[Computed]
    public function moduleOptions(): Collection
    {
        return $this->allPermissions
            ->groupBy('module')
            ->map(function (Collection $permissions, string $module): array {
                return [
                    'name' => $module,
                    'count' => $permissions->count(),
                    'roles' => $permissions->flatMap(fn (Permission $permission): Collection => $permission->roles->pluck('id'))->unique()->count(),
                    'meta' => $this->moduleMeta($module),
                ];
            })
            ->sortBy('name')
            ->values();
    }

    #[Computed]
    public function roles(): Collection
    {
        $query = Role::query()
            ->where('status', 'active')
            ->withCount('permissions')
            ->orderBy('name');

        if (! $this->canManagePermissions()) {
            $query->whereKey(Auth::user()?->role_id);
        }

        return $query->get(['id', 'name', 'slug', 'status']);
    }

    #[Computed]
    public function selectedPermission(): ?Permission
    {
        return $this->allPermissions->firstWhere('id', $this->selectedPermissionId)
            ?? $this->filteredPermissions->first();
    }

    #[Computed]
    public function metrics(): array
    {
        $permissions = $this->allPermissions;

        return [
            'modules' => $permissions->pluck('module')->unique()->count(),
            'total' => $permissions->count(),
            'critical' => $permissions->filter(fn (Permission $permission): bool => $this->isCritical($permission))->count(),
            'roles' => $permissions->flatMap(fn (Permission $permission): Collection => $permission->roles->pluck('id'))->unique()->count(),
        ];
    }

    #[Computed]
    public function recentActivity(): Collection
    {
        return AuditLog::query()
            ->where(function ($query): void {
                $query
                    ->whereIn('module', ['Permisos', 'Roles', 'permissions', 'roles'])
                    ->orWhereIn('action', ['permission.created', 'permission.updated', 'permission.deleted', 'role.permission.updated']);
            })
            ->with('actor:id,name')
            ->latest('created_at')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function unassignedPermissions(): int
    {
        return $this->allPermissions->filter(fn (Permission $permission): bool => $permission->roles_count === 0)->count();
    }

    public function canManagePermissions(): bool
    {
        return Auth::user()?->isAdministrador() ?? false;
    }

    public function permissionType(Permission $permission): array
    {
        $action = Str::afterLast($permission->slug, '.');

        return match (true) {
            $action === 'view' => ['value' => 'view', 'label' => 'Ver', 'icon' => 'bi-eye', 'tone' => 'blue'],
            $action === 'create' => ['value' => 'create', 'label' => 'Crear', 'icon' => 'bi-plus-circle', 'tone' => 'green'],
            $action === 'edit' => ['value' => 'edit', 'label' => 'Editar', 'icon' => 'bi-pencil-square', 'tone' => 'purple'],
            in_array($action, ['delete', 'disable', 'delete_images'], true) => ['value' => 'critical', 'label' => 'Crítico', 'icon' => 'bi-shield-exclamation', 'tone' => 'red'],
            in_array($action, ['assign', 'assign_role', 'assign_lead'], true) => ['value' => 'assign', 'label' => 'Asignar', 'icon' => 'bi-person-plus', 'tone' => 'amber'],
            default => ['value' => 'manage', 'label' => 'Gestionar', 'icon' => 'bi-sliders', 'tone' => 'slate'],
        };
    }

    public function moduleMeta(string $module): array
    {
        return match (Str::lower($module)) {
            'dashboard' => ['icon' => 'bi-grid-1x2', 'tone' => 'blue'],
            'leads' => ['icon' => 'bi-people', 'tone' => 'amber'],
            'pipeline' => ['icon' => 'bi-kanban', 'tone' => 'cyan'],
            'propiedades' => ['icon' => 'bi-house', 'tone' => 'green'],
            'seguimientos' => ['icon' => 'bi-check2-square', 'tone' => 'blue'],
            'calendario', 'citas' => ['icon' => 'bi-calendar3', 'tone' => 'purple'],
            'reservas' => ['icon' => 'bi-bookmark-check', 'tone' => 'red'],
            'ventas' => ['icon' => 'bi-cash-stack', 'tone' => 'green'],
            'comisiones' => ['icon' => 'bi-percent', 'tone' => 'amber'],
            'reportes' => ['icon' => 'bi-bar-chart', 'tone' => 'purple'],
            'usuarios' => ['icon' => 'bi-person-badge', 'tone' => 'blue'],
            'roles', 'permisos' => ['icon' => 'bi-shield-check', 'tone' => 'red'],
            'configuracion' => ['icon' => 'bi-gear', 'tone' => 'slate'],
            'notificaciones' => ['icon' => 'bi-bell', 'tone' => 'red'],
            'automatizaciones' => ['icon' => 'bi-lightning-charge', 'tone' => 'purple'],
            'notas' => ['icon' => 'bi-sticky', 'tone' => 'cyan'],
            default => ['icon' => 'bi-grid', 'tone' => 'slate'],
        };
    }

    public function roleTone(Role $role): string
    {
        return match ($role->slug) {
            'administrador' => 'blue',
            'supervisor' => 'cyan',
            'asesor' => 'green',
            default => 'purple',
        };
    }

    private function keepSelectionVisible(): void
    {
        if (! $this->filteredPermissions->contains('id', $this->selectedPermissionId)) {
            $this->selectedPermissionId = $this->filteredPermissions->first()?->getKey();
        }
    }

    public function isCritical(Permission $permission): bool
    {
        return $this->permissionType($permission)['value'] === 'critical';
    }

    private function assertCanManagePermissions(): void
    {
        abort_unless($this->canManagePermissions(), 403);
    }

    private function resetPermissionForm(): void
    {
        $this->editingPermissionId = null;
        $this->form = [
            'name' => '',
            'slug' => '',
            'description' => '',
            'module' => '',
        ];
        $this->resetValidation();
    }
};
?>

<section class="crm-permissions-page">
    <header class="crm-card crm-permissions-header">
        <div>
            <p class="crm-eyebrow">Administración</p>
            <h1 class="crm-module-title">Permisos</h1>
            <p class="crm-module-description">Catálogo maestro de permisos utilizados por roles, Gates y Policies.</p>
            <p class="crm-permissions-scope"><i class="bi bi-database-check"></i> Datos actualizados desde la base de datos · {{ $this->metrics['total'] }} permisos visibles para tu rol</p>
        </div>
        <div class="crm-permissions-header-actions">
            @if ($this->canManagePermissions())
                <button type="button" class="crm-permissions-primary-button" wire:click="createPermission"><i class="bi bi-plus-lg"></i> Nuevo permiso</button>
                <a href="{{ route('roles.index') }}" class="crm-permissions-secondary-button" wire:navigate><i class="bi bi-people"></i> Gestionar roles</a>
            @endif
            <button type="button" class="crm-permissions-secondary-button" wire:click="exportPermissions"><i class="bi bi-download"></i> Exportar</button>
        </div>
    </header>

    @if (session('permission-status'))
        <div class="crm-permissions-flash is-success"><i class="bi bi-check-circle-fill"></i>{{ session('permission-status') }}</div>
    @endif
    @if (session('permission-error'))
        <div class="crm-permissions-flash is-error"><i class="bi bi-exclamation-triangle-fill"></i>{{ session('permission-error') }}</div>
    @endif

    <section class="crm-permissions-metrics">
        <article class="crm-card crm-permissions-metric is-blue"><span><i class="bi bi-grid-3x3-gap"></i></span><strong>{{ $this->metrics['modules'] }}</strong><small>Módulos del sistema</small><em>Organización de permisos</em></article>
        <article class="crm-card crm-permissions-metric is-amber"><span><i class="bi bi-key"></i></span><strong>{{ $this->metrics['total'] }}</strong><small>Permisos totales</small><em>Disponibles para este alcance</em></article>
        <article class="crm-card crm-permissions-metric is-red"><span><i class="bi bi-shield-exclamation"></i></span><strong>{{ $this->metrics['critical'] }}</strong><small>Permisos críticos</small><em>Requieren validación</em></article>
        <article class="crm-card crm-permissions-metric is-purple"><span><i class="bi bi-people"></i></span><strong>{{ $this->metrics['roles'] }}</strong><small>Roles vinculados</small><em>Usan estos permisos</em></article>
    </section>

    <section class="crm-card crm-permissions-toolbar">
        <label class="crm-permissions-search"><span>Buscar permisos...</span><div><i class="bi bi-search"></i><input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar por nombre, slug o descripción..."></div></label>
        <label><span>Módulo</span><select wire:model.live="moduleFilter"><option value="">Todos los módulos</option>@foreach ($this->moduleOptions as $module)<option value="{{ $module['name'] }}">{{ $module['name'] }}</option>@endforeach</select></label>
        <label><span>Tipo de permiso</span><select wire:model.live="typeFilter"><option value="">Todos</option><option value="view">Ver</option><option value="create">Crear</option><option value="edit">Editar</option><option value="assign">Asignar</option><option value="manage">Gestionar</option><option value="critical">Crítico</option></select></label>
        <label><span>Rol asociado</span><select wire:model.live="roleFilter"><option value="">Todos</option>@foreach ($this->roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select></label>
        <label><span>Estado</span><select wire:model.live="statusFilter"><option value="active">Activos</option><option value="">Todos</option></select></label>
        <button type="button" class="crm-permissions-clear-button" wire:click="clearFilters"><i class="bi bi-x-lg"></i> Limpiar filtros</button>
    </section>

    <div class="crm-permissions-workspace">
        <aside class="crm-card crm-permissions-modules">
            <div class="crm-permissions-panel-heading"><div><p class="crm-eyebrow">Organización</p><h2>Módulos del sistema</h2></div><span>{{ $this->moduleOptions->count() }}</span></div>
            <button type="button" class="crm-permissions-module-item {{ $moduleFilter === '' ? 'is-selected' : '' }}" wire:click="$set('moduleFilter', '')"><span class="crm-permissions-module-icon is-blue"><i class="bi bi-grid-1x2"></i></span><span><strong>Todos los módulos</strong><small>{{ $this->metrics['total'] }} permisos</small></span><i class="bi bi-chevron-right"></i></button>
            @foreach ($this->moduleOptions as $module)
                <button type="button" class="crm-permissions-module-item {{ $moduleFilter === $module['name'] ? 'is-selected' : '' }}" wire:key="module-filter-{{ Str::slug($module['name']) }}" wire:click="$set('moduleFilter', '{{ addslashes($module['name']) }}')"><span class="crm-permissions-module-icon is-{{ $module['meta']['tone'] }}"><i class="bi {{ $module['meta']['icon'] }}"></i></span><span><strong>{{ $module['name'] }}</strong><small>{{ $module['count'] }} permisos · {{ $module['roles'] }} roles</small></span><i class="bi bi-chevron-right"></i></button>
            @endforeach
        </aside>

        <main class="crm-permissions-main">
            <section class="crm-card crm-permissions-list-card">
                @php($activeModule = $moduleFilter !== '' ? $moduleFilter : ($this->selectedPermission?->module ?? 'Todos los módulos'))
                <div class="crm-permissions-panel-heading"><div><p class="crm-eyebrow">Catálogo filtrado</p><h2>Permisos de {{ $activeModule }}</h2><small>{{ $this->filteredPermissions->count() }} permisos · {{ $this->roles->count() }} roles disponibles</small></div>@if ($this->canManagePermissions())<button type="button" class="crm-permissions-primary-button crm-permissions-compact-button" wire:click="createPermission"><i class="bi bi-plus-lg"></i> Nuevo permiso</button>@endif</div>
                <div class="crm-permissions-table">
                    <div class="crm-permissions-table-row is-heading"><span></span><span>Permiso</span><span>Tipo</span><span>Roles asociados</span><span>Estado</span><span>Acciones</span></div>
                    @forelse ($this->filteredPermissions as $permission)
                        @php($permissionType = $this->permissionType($permission))
                        <div class="crm-permissions-table-row {{ $selectedPermissionId === $permission->id ? 'is-selected' : '' }}" wire:key="permission-row-{{ $permission->id }}">
                            <button type="button" class="crm-permissions-row-selector" wire:click="selectPermission({{ $permission->id }})" aria-label="Seleccionar {{ $permission->name }}"><i class="bi {{ $selectedPermissionId === $permission->id ? 'bi-check-square-fill' : 'bi-square' }}"></i></button>
                            <button type="button" class="crm-permissions-name-cell" wire:click="selectPermission({{ $permission->id }})"><strong>{{ $permission->name }}</strong><small>{{ $permission->slug }}</small><em>{{ $permission->description ?: 'Permiso del módulo '.$permission->module }}</em></button>
                            <span class="crm-permissions-type-badge is-{{ $permissionType['tone'] }}"><i class="bi {{ $permissionType['icon'] }}"></i>{{ $permissionType['label'] }}</span>
                            <div class="crm-permissions-role-chips">@forelse ($permission->roles->take(3) as $role)<span class="is-{{ $this->roleTone($role) }}"><i class="bi bi-person-badge"></i>{{ $role->name }}</span>@empty<span class="is-empty">Sin rol</span>@endforelse @if ($permission->roles_count > 3)<small>+{{ $permission->roles_count - 3 }}</small>@endif</div>
                            <span class="crm-permissions-status-badge"><i></i> Activo</span>
                            <div class="crm-permissions-row-actions"><button type="button" wire:click="selectPermission({{ $permission->id }})" title="Ver detalle"><i class="bi bi-eye"></i></button>@if ($this->canManagePermissions())<button type="button" wire:click="editPermission({{ $permission->id }})" title="Editar"><i class="bi bi-pencil"></i></button><button type="button" wire:click="deletePermission({{ $permission->id }})" title="Eliminar"><i class="bi bi-trash3"></i></button>@endif</div>
                        </div>
                    @empty
                        <div class="crm-empty">No hay permisos que coincidan con los filtros seleccionados.</div>
                    @endforelse
                </div>
                <div class="crm-permissions-list-footer"><span>Mostrando {{ $this->filteredPermissions->count() }} de {{ $this->allPermissions->count() }} permisos</span><span><i class="bi bi-database"></i> Consulta en tiempo real</span></div>
            </section>

            @if ($this->selectedPermission)
                @php($selectedType = $this->permissionType($this->selectedPermission))
                <div class="crm-permissions-detail-grid">
                    <section class="crm-card crm-permissions-detail-card">
                        <div class="crm-permissions-panel-heading"><div><p class="crm-eyebrow">Ficha técnica</p><h2>Detalles del permiso seleccionado</h2></div>@if ($this->canManagePermissions())<button type="button" class="crm-permissions-secondary-button crm-permissions-compact-button" wire:click="editPermission({{ $this->selectedPermission->id }})"><i class="bi bi-pencil"></i> Editar permiso</button>@endif</div>
                        <dl class="crm-permissions-detail-list"><div><dt>Nombre del permiso</dt><dd>{{ $this->selectedPermission->name }}</dd></div><div><dt>Slug / clave técnica</dt><dd><code>{{ $this->selectedPermission->slug }}</code><button type="button" class="crm-permissions-copy-button" x-data x-on:click="navigator.clipboard.writeText('{{ $this->selectedPermission->slug }}')" title="Copiar slug"><i class="bi bi-copy"></i></button></dd></div><div><dt>Módulo</dt><dd><span class="crm-permissions-module-inline"><i class="bi {{ $this->moduleMeta($this->selectedPermission->module)['icon'] }}"></i>{{ $this->selectedPermission->module }}</span></dd></div><div><dt>Descripción</dt><dd class="is-description">{{ $this->selectedPermission->description ?: 'Este permiso controla el acceso a las funciones del módulo.' }}</dd></div><div><dt>Tipo de permiso</dt><dd><span class="crm-permissions-type-badge is-{{ $selectedType['tone'] }}"><i class="bi {{ $selectedType['icon'] }}"></i>{{ $selectedType['label'] }}</span><small>{{ $this->isCritical($this->selectedPermission) ? 'Permiso sensible para la seguridad del sistema.' : 'Permiso operativo del módulo.' }}</small></dd></div></dl>
                    </section>
                    <aside class="crm-card crm-permissions-governance-card"><div class="crm-permissions-panel-heading"><div><p class="crm-eyebrow">Gobierno</p><h2>Información y gobierno</h2></div><i class="bi bi-shield-check"></i></div><div class="crm-permissions-governance-status"><span><i class="bi bi-check-circle-fill"></i> Activo</span><small>Disponible para ser asignado a roles.</small></div><div class="crm-permissions-governance-critical"><strong>¿Es un permiso crítico?</strong><span class="{{ $this->isCritical($this->selectedPermission) ? 'is-yes' : 'is-no' }}"><i class="bi {{ $this->isCritical($this->selectedPermission) ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill' }}"></i>{{ $this->isCritical($this->selectedPermission) ? 'Sí, requiere validación' : 'No' }}</span></div><div class="crm-permissions-linked-roles"><strong>Roles vinculados ({{ $this->selectedPermission->roles_count }})</strong><div>@forelse ($this->selectedPermission->roles as $role)<span class="is-{{ $this->roleTone($role) }}"><i class="bi bi-person-badge"></i>{{ $role->name }}</span>@empty<small>Este permiso aún no ha sido asignado.</small>@endforelse</div>@if ($this->canManagePermissions())<a href="{{ route('roles.index') }}" wire:navigate>Gestionar asignación en Roles <i class="bi bi-arrow-right"></i></a>@endif</div></aside>
                </div>
            @else
                <section class="crm-card crm-permissions-empty-detail"><i class="bi bi-key"></i><h2>Selecciona un permiso</h2><p>Elige un registro para consultar su configuración y los roles vinculados.</p></section>
            @endif

            <div class="crm-permissions-bottom-grid">
                <section class="crm-card crm-permissions-activity-card"><div class="crm-permissions-panel-heading"><div><p class="crm-eyebrow">Auditoría</p><h2>Actividad reciente</h2></div><span>{{ $this->recentActivity->count() }} registros</span></div>@forelse ($this->recentActivity as $activity)<div class="crm-permissions-activity-row"><span class="crm-permissions-activity-icon is-blue"><i class="bi bi-shield-check"></i></span><span><strong>{{ $activity->description ?: Str::headline($activity->action) }}</strong><small>{{ $activity->actor?->name ?: 'Sistema' }} · {{ $activity->created_at?->diffForHumans() }}</small></span><i class="bi bi-three-dots-vertical"></i></div>@empty<div class="crm-empty">Aún no hay actividad registrada para permisos o roles.</div>@endforelse</section>
                <section class="crm-card crm-permissions-alerts-card"><div class="crm-permissions-panel-heading"><div><p class="crm-eyebrow">Control</p><h2>Alertas y validaciones</h2></div><span class="crm-permissions-alert-count">{{ $this->metrics['critical'] }}</span></div><div class="crm-permissions-alert-row is-red"><i class="bi bi-shield-exclamation"></i><span><strong>{{ $this->metrics['critical'] }} permisos críticos</strong><small>Revisar asignación y validación de permisos sensibles.</small></span><i class="bi bi-chevron-right"></i></div><div class="crm-permissions-alert-row is-amber"><i class="bi bi-exclamation-triangle"></i><span><strong>{{ $this->unassignedPermissions }} permisos sin roles</strong><small>Permisos creados que todavía no se utilizan.</small></span><i class="bi bi-chevron-right"></i></div><div class="crm-permissions-alert-row is-blue"><i class="bi bi-info-circle"></i><span><strong>{{ $this->metrics['roles'] }} roles vinculados</strong><small>La asignación se administra desde el módulo Roles.</small></span><i class="bi bi-chevron-right"></i></div></section>
            </div>
        </main>
    </div>

    @if ($showForm)
        <div class="crm-permissions-modal-layer" wire:key="permission-form-modal">
            <div class="crm-permissions-modal-backdrop" wire:click="closePermissionForm"></div>
            <section class="crm-card crm-permissions-form-modal" role="dialog" aria-modal="true" aria-labelledby="permission-form-title">
                <div class="crm-permissions-form-heading"><div><p class="crm-eyebrow">Administración de accesos</p><h2 id="permission-form-title">{{ $editingPermissionId ? 'Editar permiso' : 'Nuevo permiso' }}</h2><p>Define la clave técnica que utilizarán los roles y las Policies.</p></div><button type="button" wire:click="closePermissionForm" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button></div>
                <form wire:submit="savePermission"><div class="crm-permissions-form-grid"><label><span>Nombre visible <em>*</em></span><input type="text" wire:model="form.name" required placeholder="Ej. Exportar reportes">@error('form.name')<small>{{ $message }}</small>@enderror</label><label><span>Slug / clave técnica <em>*</em></span><input type="text" wire:model="form.slug" required placeholder="reports.export">@error('form.slug')<small>{{ $message }}</small>@enderror</label><label><span>Módulo <em>*</em></span><input type="text" wire:model="form.module" required placeholder="Reportes">@error('form.module')<small>{{ $message }}</small>@enderror</label><label><span>Descripción</span><input type="text" wire:model="form.description" placeholder="Describe el alcance del permiso">@error('form.description')<small>{{ $message }}</small>@enderror</label></div><div class="crm-permissions-form-actions"><button type="button" class="crm-permissions-secondary-button" wire:click="closePermissionForm">Cancelar</button><button type="submit" class="crm-permissions-primary-button"><i class="bi bi-check2"></i> {{ $editingPermissionId ? 'Guardar cambios' : 'Crear permiso' }}</button></div></form>
            </section>
        </div>
    @endif
</section>
