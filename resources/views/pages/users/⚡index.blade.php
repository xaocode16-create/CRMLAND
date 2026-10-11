<?php

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Usuarios')] class extends Component
{
    private const PAGE_SIZE = 8;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $roleId = '';

    #[Url]
    public string $supervisorId = '';

    #[Url]
    public int $page = 1;

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $selectedUserId = null;

    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('users.view');
        Auth::user()->loadMissing('role');
        $this->resetForm();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'roleId', 'supervisorId'], true)) {
            $this->page = 1;
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->roleId = '';
        $this->supervisorId = '';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($page, $this->totalPages));
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'username' => '',
            'email' => '',
            'phone' => '',
            'password' => '',
            'role_id' => Role::query()->where('slug', 'asesor')->value('id'),
            'supervisor_user_id' => null,
            'status' => UserStatus::Active->value,
        ];
    }

    public function create(): void
    {
        Gate::authorize('users.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $userId): void
    {
        Gate::authorize('users.edit');
        $user = User::query()->findOrFail($userId);
        abort_unless($this->canManageUser($user), 403);

        $this->editingId = $user->id;
        $this->form = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => '',
            'role_id' => $user->role_id,
            'supervisor_user_id' => $user->supervisor_user_id,
            'status' => $user->status,
        ];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
    }

    public function save(): void
    {
        $user = $this->editingId ? User::query()->findOrFail($this->editingId) : null;
        $user ? Gate::authorize('users.edit') : Gate::authorize('users.create');

        if ($user instanceof User) {
            abort_unless($this->canManageUser($user), 403);
        }

        Gate::authorize('users.assign_role');

        $validated = $this->validate([
            'form.name' => ['required', 'string', 'max:160'],
            'form.username' => ['nullable', 'string', 'max:80', Rule::unique('users', 'username')->ignore($this->editingId)],
            'form.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
            'form.role_id' => ['required', 'integer', 'exists:roles,id'],
            'form.supervisor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'form.status' => ['required', Rule::in(array_column(UserStatus::cases(), 'value'))],
        ])['form'];

        $supervisorId = $validated['supervisor_user_id'] ? (int) $validated['supervisor_user_id'] : null;
        abort_unless($supervisorId === null || $supervisorId !== (int) ($user?->id ?? 0), 422, 'Un usuario no puede ser su propio supervisor.');

        $payload = [
            'name' => $validated['name'],
            'username' => $validated['username'] ?: null,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'role_id' => (int) $validated['role_id'],
            'supervisor_user_id' => $supervisorId,
            'status' => $validated['status'],
        ];

        if ($validated['password'] !== '') {
            $payload['password'] = $validated['password'];
        }

        if ($user instanceof User) {
            $user->update($payload);
            Flux::toast(variant: 'success', text: 'Usuario actualizado.');
        } else {
            User::query()->create($payload);
            Flux::toast(variant: 'success', text: 'Usuario creado.');
        }

        $this->closeForm();
    }

    public function openUser(int $userId): void
    {
        $user = $this->visibleUsersQuery()->findOrFail($userId);
        $this->selectedUserId = $user->id;
    }

    public function closeUser(): void
    {
        $this->selectedUserId = null;
    }

    public function disable(int $userId): void
    {
        Gate::authorize('users.disable');
        $user = User::query()->findOrFail($userId);
        abort_unless($this->canManageUser($user), 403);
        abort_if($user->is(Auth::user()), 422, 'No puedes desactivar tu propio usuario.');

        $user->update(['status' => UserStatus::Inactive->value]);
        Flux::toast(variant: 'success', text: 'Usuario desactivado.');
    }

    public function reactivate(int $userId): void
    {
        Gate::authorize('users.reactivate');
        $user = User::query()->findOrFail($userId);
        abort_unless($this->canManageUser($user), 403);

        $user->update(['status' => UserStatus::Active->value]);
        Flux::toast(variant: 'success', text: 'Usuario reactivado.');
    }

    public function exportUsers(): StreamedResponse
    {
        Gate::authorize('users.view');
        $users = $this->filteredUsersQuery()->with(['role', 'supervisor'])->orderBy('name')->limit(5000)->get();

        return response()->streamDownload(function () use ($users): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Usuario', 'Correo', 'Telefono', 'Rol', 'Supervisor', 'Area', 'Estado', 'Ultimo ingreso', '2FA'], ';');

            foreach ($users as $user) {
                fputcsv($handle, [
                    $user->name,
                    $user->email,
                    $user->phone,
                    $user->role?->name ?? 'Sin rol',
                    $user->supervisor?->name ?? 'Sin supervisor',
                    $this->areaLabel($user),
                    UserStatus::tryFrom($user->status)?->label() ?? $user->status,
                    $user->last_login_at?->format('d/m/Y H:i') ?? 'Sin ingreso',
                    $this->twoFactorLabel($user),
                ], ';');
            }

            fclose($handle);
        }, 'usuarios-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    #[Computed]
    public function currentRoleLabel(): string
    {
        return Auth::user()->role?->name ?? 'Asesor';
    }

    #[Computed]
    public function users(): Collection
    {
        return $this->filteredUsersQuery()
            ->with(['role', 'supervisor'])
            ->orderBy('name')
            ->forPage($this->page, self::PAGE_SIZE)
            ->get();
    }

    #[Computed]
    public function userCount(): int
    {
        return $this->filteredUsersQuery()->count();
    }

    #[Computed]
    public function totalPages(): int
    {
        return max(1, (int) ceil($this->userCount / self::PAGE_SIZE));
    }

    #[Computed]
    public function pageLinks(): array
    {
        $lastPage = $this->totalPages;
        $start = max(1, min($this->page - 2, $lastPage - 4));

        return range($start, min($lastPage, $start + 4));
    }

    #[Computed]
    public function metrics(): array
    {
        $query = $this->visibleUsersQuery();
        $total = (clone $query)->count();
        $active = (clone $query)->where('status', UserStatus::Active->value)->count();
        $inactive = (clone $query)->where('status', UserStatus::Inactive->value)->count();
        $administrators = (clone $query)->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('slug', 'administrador'))->count();
        $advisors = (clone $query)->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('slug', 'asesor'))->count();
        $pendingInvitations = (clone $query)->whereNull('email_verified_at')->count();

        return [
            ['label' => 'Usuarios activos', 'value' => $active, 'detail' => '+'.$active.' en el equipo', 'icon' => 'bi-people', 'tone' => 'green'],
            ['label' => 'Administradores', 'value' => $administrators, 'detail' => $this->percentage($administrators, $total).' del total', 'icon' => 'bi-shield-check', 'tone' => 'blue'],
            ['label' => 'Asesores', 'value' => $advisors, 'detail' => $this->percentage($advisors, $total).' del total', 'icon' => 'bi-people-fill', 'tone' => 'violet'],
            ['label' => 'Invitaciones pendientes', 'value' => $pendingInvitations, 'detail' => 'sin verificar', 'icon' => 'bi-envelope', 'tone' => 'orange'],
            ['label' => 'Usuarios inactivos', 'value' => $inactive, 'detail' => $this->percentage($inactive, $total).' del total', 'icon' => 'bi-person-x', 'tone' => 'red'],
        ];
    }

    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->where('status', 'active')->with('permissions')->orderBy('name')->get(['id', 'name', 'slug']);
    }

    #[Computed]
    public function supervisors(): Collection
    {
        return User::query()
            ->whereHas('role', fn (Builder $query) => $query->whereIn('slug', ['administrador', 'supervisor']))
            ->where('status', UserStatus::Active->value)
            ->when(Auth::user()->isSupervisor(), fn (Builder $query) => $query->where(function (Builder $teamQuery): void {
                $teamQuery->whereKey(Auth::id())->orWhere('supervisor_user_id', Auth::id());
            }))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function selectedRole(): ?Role
    {
        return $this->form['role_id'] ? Role::query()->with('permissions')->find($this->form['role_id']) : null;
    }

    #[Computed]
    public function selectedUser(): ?User
    {
        return $this->selectedUserId ? $this->visibleUsersQuery()->with(['role.permissions', 'supervisor'])->find($this->selectedUserId) : null;
    }

    #[Computed]
    public function recentUsers(): Collection
    {
        return $this->visibleUsersQuery()->with('role')->latest('updated_at')->limit(5)->get();
    }

    #[Computed]
    public function alerts(): array
    {
        $query = $this->visibleUsersQuery();

        return [
            ['label' => 'Invitaciones pendientes', 'description' => 'Usuarios que aún no verifican su correo', 'value' => (clone $query)->whereNull('email_verified_at')->count(), 'icon' => 'bi-envelope', 'tone' => 'orange'],
            ['label' => 'Usuarios sin supervisor', 'description' => 'Asesores que requieren asignación', 'value' => (clone $query)->whereNull('supervisor_user_id')->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('slug', 'asesor'))->count(), 'icon' => 'bi-people', 'tone' => 'violet'],
            ['label' => 'Sin acceso reciente', 'description' => 'No han ingresado en los últimos 30 días', 'value' => (clone $query)->where('status', UserStatus::Active->value)->where(function (Builder $loginQuery): void {
                $loginQuery->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(30));
            })->count(), 'icon' => 'bi-clock', 'tone' => 'blue'],
            ['label' => 'Sin 2FA configurado', 'description' => 'Se recomienda activar la verificación en 2 pasos', 'value' => (clone $query)->where('status', UserStatus::Active->value)->whereNull('two_factor_confirmed_at')->count(), 'icon' => 'bi-shield-check', 'tone' => 'green'],
            ['label' => 'Usuarios inactivos', 'description' => 'Revisa estado y permisos de acceso', 'value' => (clone $query)->where('status', UserStatus::Inactive->value)->count(), 'icon' => 'bi-person-x', 'tone' => 'red'],
        ];
    }

    public function roleClass(?string $slug): string
    {
        return match ($slug) {
            'administrador' => 'is-admin',
            'supervisor' => 'is-supervisor',
            default => 'is-advisor',
        };
    }

    public function userStatusClass(string $status): string
    {
        return $status === UserStatus::Active->value ? 'is-active' : 'is-inactive';
    }

    public function twoFactorLabel(User $user): string
    {
        return filled($user->two_factor_confirmed_at) ? 'Activado' : 'Pendiente';
    }

    public function areaLabel(User $user): string
    {
        return match ($user->role?->slug) {
            'administrador' => 'Administración',
            'supervisor' => 'Comercial',
            default => 'Ventas',
        };
    }

    public function displayLastLogin(User $user): string
    {
        if ($user->last_login_at === null) {
            return 'Sin ingreso';
        }

        return $user->last_login_at->isToday() ? 'Hoy, '.$user->last_login_at->format('H:i') : $user->last_login_at->format('d/m/Y H:i');
    }

    private function visibleUsersQuery(): Builder
    {
        $user = Auth::user();

        return User::query()
            ->when($user->isSupervisor(), fn (Builder $query) => $query->where(function (Builder $scopeQuery) use ($user): void {
                $scopeQuery->whereKey($user->id)->orWhere('supervisor_user_id', $user->id);
            }))
            ->when($user->isAsesor(), fn (Builder $query) => $query->whereKey($user->id));
    }

    private function filteredUsersQuery(): Builder
    {
        return $this->visibleUsersQuery()
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('username', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                });
            })
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->roleId !== '', fn (Builder $query) => $query->where('role_id', (int) $this->roleId))
            ->when($this->supervisorId !== '', fn (Builder $query) => $query->where('supervisor_user_id', (int) $this->supervisorId));
    }

    private function canManageUser(User $target): bool
    {
        $user = Auth::user();

        return $user->isAdministrador()
            || $target->is($user)
            || ($user->isSupervisor() && (int) $target->supervisor_user_id === (int) $user->id);
    }

    private function percentage(int $value, int $total): string
    {
        return $total > 0 ? round(($value / $total) * 100). '%' : '0%';
    }
};
?>

<section class="crm-page crm-users-page">
    <header class="crm-page-header crm-users-page-header">
        <div>
            <p class="crm-eyebrow">Administración</p>
            <h1 class="crm-module-title">Usuarios</h1>
            <p class="crm-module-description">Gestiona los miembros del equipo, roles, supervisores, invitaciones y estado de acceso.</p>
            <p class="crm-users-scope"><i class="bi bi-shield-check" aria-hidden="true"></i> {{ $this->currentRoleLabel }} · Datos visibles según tu rol y equipo</p>
        </div>
        <div class="crm-users-header-actions">
            <flux:button icon="arrow-down-tray" wire:click="exportUsers" wire:loading.attr="disabled" wire:target="exportUsers">Exportar</flux:button>
            @can('users.create')<flux:button icon="plus" variant="primary" wire:click="create">Nuevo usuario</flux:button>@endcan
        </div>
    </header>

    <section class="crm-users-stats">
        @foreach ($this->metrics as $metric)
            <article class="crm-card crm-users-stat crm-users-stat--{{ $metric['tone'] }}"><span class="crm-users-stat-icon"><i class="bi {{ $metric['icon'] }}"></i></span><div><p>{{ $metric['label'] }}</p><strong>{{ number_format($metric['value']) }}</strong><small>{{ $metric['detail'] }}</small></div></article>
        @endforeach
    </section>

    <section class="crm-card crm-users-filters"><div class="crm-users-filter-grid"><label class="crm-users-search-field"><span>Buscar</span><div><i class="bi bi-search"></i><input type="search" wire:model.live.debounce.350ms="search" placeholder="Nombre, correo o equipo..."></div></label><label><span>Rol</span><select wire:model.live="roleId"><option value="">Todos</option>@foreach ($this->roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select></label><label><span>Estado</span><select wire:model.live="status"><option value="">Todos</option>@foreach (UserStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</select></label><label><span>Supervisor</span><select wire:model.live="supervisorId"><option value="">Todos</option>@foreach ($this->supervisors as $supervisor)<option value="{{ $supervisor->id }}">{{ $supervisor->name }}</option>@endforeach</select></label><button type="button" class="crm-users-clear" wire:click="resetFilters"><i class="bi bi-arrow-counterclockwise"></i> Limpiar filtros</button></div></section>

    <section class="crm-card crm-users-table-card"><div class="crm-users-table-heading"><div><h2>Lista de usuarios <span>{{ $this->userCount }}</span></h2><p>Control de acceso y responsables visibles para tu rol.</p></div><select class="crm-users-table-view"><option>Todos los usuarios</option></select></div><div class="crm-users-table-scroll"><table class="crm-users-table"><thead><tr><th>Usuario</th><th>Rol</th><th>Supervisor</th><th>Equipo o área</th><th>Estado</th><th>Último ingreso</th><th>2FA</th><th>Acciones</th></tr></thead><tbody>@forelse ($this->users as $user)<tr wire:key="user-{{ $user->id }}"><td><button type="button" class="crm-users-person" wire:click="openUser({{ $user->id }})"><span class="crm-users-avatar {{ $this->roleClass($user->role?->slug) }}">{{ $user->initials() }}</span><span><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></span></button></td><td><span class="crm-users-role {{ $this->roleClass($user->role?->slug) }}"><i class="bi {{ $user->isAdministrador() ? 'bi-shield-check' : ($user->isSupervisor() ? 'bi-people' : 'bi-person') }}"></i>{{ $user->role?->name ?? 'Sin rol' }}</span></td><td>{{ $user->supervisor?->name ?? '-' }}</td><td>{{ $this->areaLabel($user) }}</td><td><span class="crm-users-status {{ $this->userStatusClass($user->status) }}"><i class="bi bi-circle-fill"></i>{{ UserStatus::tryFrom($user->status)?->label() ?? $user->status }}</span></td><td><span class="crm-users-login {{ $user->last_login_at ? 'is-online' : '' }}"><i class="bi bi-circle-fill"></i>{{ $this->displayLastLogin($user) }}</span></td><td><span class="crm-users-two-factor {{ filled($user->two_factor_confirmed_at) ? 'is-enabled' : 'is-pending' }}"><i class="bi bi-lock{{ filled($user->two_factor_confirmed_at) ? '-fill' : '' }}"></i>{{ $this->twoFactorLabel($user) }}</span></td><td><div class="crm-users-row-actions"><button type="button" wire:click="openUser({{ $user->id }})" title="Ver usuario"><i class="bi bi-eye"></i></button>@can('users.edit')<button type="button" wire:click="edit({{ $user->id }})" title="Editar usuario"><i class="bi bi-pencil-square"></i></button>@endcan @if ($user->status === UserStatus::Active->value) @can('users.disable')<button type="button" wire:click="disable({{ $user->id }})" wire:confirm="¿Desactivar este usuario?" title="Desactivar usuario"><i class="bi bi-person-x"></i></button>@endcan @else @can('users.reactivate')<button type="button" wire:click="reactivate({{ $user->id }})" title="Reactivar usuario"><i class="bi bi-person-check"></i></button>@endcan @endif</div></td></tr>@empty<tr><td colspan="8"><div class="crm-empty"><i class="bi bi-people"></i><strong>No hay usuarios con estos filtros.</strong><span>Prueba con otros criterios o limpia la búsqueda.</span></div></td></tr>@endforelse</tbody></table></div><div class="crm-users-pagination"><span>Mostrando {{ $this->users->count() }} de {{ $this->userCount }} usuarios</span><div><button type="button" wire:click="goToPage({{ max(1, $page - 1) }})" @disabled($page <= 1)><i class="bi bi-chevron-left"></i></button>@foreach ($this->pageLinks as $link)<button type="button" class="{{ $link === $page ? 'is-active' : '' }}" wire:click="goToPage({{ $link }})">{{ $link }}</button>@endforeach<button type="button" wire:click="goToPage({{ min($this->totalPages, $page + 1) }})" @disabled($page >= $this->totalPages)><i class="bi bi-chevron-right"></i></button></div></div></section>

    <section class="crm-users-bottom-grid"><section class="crm-card crm-users-bottom-card"><div class="crm-users-bottom-heading"><div><h2><i class="bi bi-clock-history"></i> Actividad reciente</h2><p>Últimos cambios registrados en usuarios.</p></div><a href="#" wire:click.prevent="$refresh">Ver todo <i class="bi bi-arrow-right"></i></a></div><div class="crm-users-activity-list">@forelse ($this->recentUsers as $user)<article><span class="crm-users-activity-icon {{ $this->roleClass($user->role?->slug) }}"><i class="bi bi-person-gear"></i></span><div><strong>{{ $user->name }} {{ $user->created_at?->eq($user->updated_at) ? 'se registró' : 'fue actualizado' }}</strong><small>{{ $user->role?->name ?? 'Sin rol' }} · {{ $user->updated_at?->diffForHumans() }}</small></div></article>@empty<div class="crm-users-mini-empty">No hay actividad reciente.</div>@endforelse</div></section><section class="crm-card crm-users-bottom-card"><div class="crm-users-bottom-heading"><div><h2><i class="bi bi-exclamation-triangle"></i> Alertas y pendientes</h2><p>Recomendaciones de administración.</p></div><a href="#" wire:click.prevent="$refresh">Ver todo</a></div><div class="crm-users-alert-list">@foreach ($this->alerts as $alert)<article class="crm-users-alert crm-users-alert--{{ $alert['tone'] }}"><span><i class="bi {{ $alert['icon'] }}"></i></span><div><strong>{{ $alert['value'] }} {{ $alert['label'] }}</strong><small>{{ $alert['description'] }}</small></div><button type="button" wire:click="resetFilters">Revisar</button></article>@endforeach</div></section></section>

    @if ($showForm)
        <div class="crm-users-modal-layer" wire:key="user-form-modal"><div class="crm-users-modal-backdrop" wire:click="closeForm"></div><section class="crm-card crm-users-form-modal" role="dialog" aria-modal="true" aria-labelledby="user-form-title"><div class="crm-users-form-heading"><div><p class="crm-eyebrow">Administración de accesos</p><h2 id="user-form-title">{{ $editingId ? 'Editar usuario' : 'Nuevo usuario' }}</h2><p>Completa los datos y asigna el rol correspondiente al nivel de acceso.</p></div><button type="button" wire:click="closeForm" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button></div><form wire:submit="save"><div class="crm-users-form-sections"><section><div class="crm-users-form-section-heading"><span><i class="bi bi-person-vcard"></i></span><div><h3>Información del usuario</h3><p>Datos de identificación y contacto.</p></div></div><div class="crm-users-form-grid"><label><span>Nombre completo <em>*</em></span><input type="text" wire:model="form.name" required>@error('form.name')<small>{{ $message }}</small>@enderror</label><label><span>Usuario</span><input type="text" wire:model="form.username" placeholder="Opcional">@error('form.username')<small>{{ $message }}</small>@enderror</label><label><span>Correo electrónico <em>*</em></span><input type="email" wire:model="form.email" required>@error('form.email')<small>{{ $message }}</small>@enderror</label><label><span>Teléfono</span><input type="text" wire:model="form.phone"></label></div></section><section><div class="crm-users-form-section-heading"><span><i class="bi bi-shield-check"></i></span><div><h3>Rol y organización</h3><p>Los permisos se heredan del rol seleccionado.</p></div></div><div class="crm-users-form-grid"><label><span>Rol <em>*</em></span><select wire:model.live="form.role_id" required>@foreach ($this->roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select>@error('form.role_id')<small>{{ $message }}</small>@enderror</label><label><span>Supervisor</span><select wire:model="form.supervisor_user_id"><option value="">Sin supervisor</option>@foreach ($this->supervisors as $supervisor)<option value="{{ $supervisor->id }}">{{ $supervisor->name }}</option>@endforeach</select></label><label><span>Estado</span><select wire:model="form.status">@foreach (UserStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</select></label></div>@if ($this->selectedRole)<div class="crm-users-role-summary"><strong>Permisos heredados de {{ $this->selectedRole->name }}</strong><div>@foreach ($this->selectedRole->permissions->take(6) as $permission)<span><i class="bi bi-check-circle-fill"></i>{{ $permission->name }}</span>@endforeach</div></div>@endif</section><section><div class="crm-users-form-section-heading"><span><i class="bi bi-key"></i></span><div><h3>Seguridad de acceso</h3><p>{{ $editingId ? 'Deja vacío para conservar la contraseña actual.' : 'Define una contraseña segura para el acceso inicial.' }}</p></div></div><label class="crm-users-form-password"><span>{{ $editingId ? 'Nueva contraseña' : 'Contraseña' }} {{ $editingId ? '' : '*' }}</span><input type="password" wire:model="form.password" {{ $editingId ? '' : 'required' }} minlength="8" autocomplete="new-password">@error('form.password')<small>{{ $message }}</small>@enderror</label></section></div><div class="crm-users-form-actions"><button type="button" class="crm-users-secondary-button" wire:click="closeForm">Cancelar</button><button type="submit" class="crm-users-primary-button" wire:loading.attr="disabled"><i class="bi bi-check2"></i> {{ $editingId ? 'Guardar cambios' : 'Crear usuario' }}</button></div></form></section></div>
    @endif

    @if ($this->selectedUser)
        <div class="crm-users-modal-layer" wire:key="user-detail-modal"><div class="crm-users-modal-backdrop" wire:click="closeUser"></div><section class="crm-card crm-users-detail-modal" role="dialog" aria-modal="true"><div class="crm-users-form-heading"><div><p class="crm-eyebrow">Detalle de acceso</p><h2>{{ $this->selectedUser->name }}</h2><p>{{ $this->selectedUser->email }} · {{ $this->selectedUser->role?->name ?? 'Sin rol' }}</p></div><button type="button" wire:click="closeUser" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button></div><div class="crm-users-detail-profile"><span class="crm-users-detail-avatar {{ $this->roleClass($this->selectedUser->role?->slug) }}">{{ $this->selectedUser->initials() }}</span><div><strong>{{ $this->selectedUser->name }}</strong><span>{{ $this->areaLabel($this->selectedUser) }}</span><small>{{ $this->selectedUser->phone ?: 'Sin teléfono registrado' }}</small></div><span class="crm-users-status {{ $this->userStatusClass($this->selectedUser->status) }}"><i class="bi bi-circle-fill"></i>{{ UserStatus::tryFrom($this->selectedUser->status)?->label() }}</span></div><div class="crm-users-detail-grid"><div><span>Supervisor</span><strong>{{ $this->selectedUser->supervisor?->name ?? 'Sin supervisor' }}</strong></div><div><span>Último ingreso</span><strong>{{ $this->displayLastLogin($this->selectedUser) }}</strong></div><div><span>Verificación 2FA</span><strong>{{ $this->twoFactorLabel($this->selectedUser) }}</strong></div><div><span>Miembro desde</span><strong>{{ $this->selectedUser->created_at?->format('d/m/Y') }}</strong></div></div></section></div>
    @endif
</section>
