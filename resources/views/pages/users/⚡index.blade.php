<?php

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Usuarios')] class extends Component
{
    public string $search = '';
    public string $status = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('users.view');
        $this->resetForm();
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

        $payload = [
            'name' => $validated['name'],
            'username' => $validated['username'] ?: null,
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?: null,
            'role_id' => (int) $validated['role_id'],
            'supervisor_user_id' => $validated['supervisor_user_id'] ? (int) $validated['supervisor_user_id'] : null,
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

        $this->showForm = false;
        $this->resetForm();
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

    #[Computed]
    public function users(): \Illuminate\Support\Collection
    {
        return $this->visibleUsersQuery()
            ->with(['role', 'supervisor'])
            ->when($this->search !== '', fn ($query) => $query->where(function ($query): void {
                $query->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%')
                    ->orWhere('username', 'like', '%'.$this->search.'%');
            }))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderBy('name')
            ->limit(120)
            ->get();
    }

    #[Computed]
    public function roles(): \Illuminate\Support\Collection
    {
        return Role::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']);
    }

    #[Computed]
    public function supervisors(): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['administrador', 'supervisor']))
            ->where('status', UserStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function visibleUsersQuery(): Builder
    {
        $user = Auth::user();

        return User::query()
            ->when($user->isSupervisor(), fn ($query) => $query->where(function ($query) use ($user): void {
                $query->whereKey($user->id)->orWhere('supervisor_user_id', $user->id);
            }))
            ->when($user->isAsesor(), fn ($query) => $query->whereKey($user->id));
    }

    private function canManageUser(User $target): bool
    {
        $user = Auth::user();

        return $user->isAdministrador()
            || $target->is($user)
            || ($user->isSupervisor() && (int) $target->supervisor_user_id === (int) $user->id);
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Administracion</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Usuarios</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Equipo comercial, roles, supervisores y estado de acceso.</p>
        </div>
        <div class="crm-actions">
            <flux:input wire:model.live.debounce.350ms="search" label="Buscar" />
            <flux:select wire:model.live="status" label="Estado"><option value="">Todos</option>@foreach (UserStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
            @can('users.create')
                <flux:button icon="plus" variant="primary" wire:click="create">Nuevo usuario</flux:button>
            @endcan
        </div>
    </header>

    @if ($showForm)
        <section class="crm-card crm-panel-pad">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><p class="crm-eyebrow">{{ $editingId ? 'Edicion' : 'Registro' }}</p><h2 class="text-lg font-bold text-[var(--color-crm-navy)]">{{ $editingId ? 'Editar usuario' : 'Nuevo usuario' }}</h2></div>
                <flux:button icon="x-mark" wire:click="$set('showForm', false)">Cerrar</flux:button>
            </div>
            <form wire:submit="save" class="space-y-4">
                <div class="crm-form-grid">
                    <flux:input wire:model="form.name" label="Nombre" required />
                    <flux:input wire:model="form.username" label="Usuario" />
                    <flux:input wire:model="form.email" label="Correo" type="email" required />
                    <flux:input wire:model="form.phone" label="Telefono" />
                    <flux:input wire:model="form.password" label="{{ $editingId ? 'Nueva contrasena' : 'Contrasena' }}" type="password" />
                    <flux:select wire:model="form.role_id" label="Rol" required>
                        @foreach ($this->roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="form.supervisor_user_id" label="Supervisor">
                        <option value="">Sin supervisor</option>
                        @foreach ($this->supervisors as $supervisor)
                            <option value="{{ $supervisor->id }}">{{ $supervisor->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="form.status" label="Estado">
                        @foreach (UserStatus::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="crm-form-actions">
                    <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Guardar</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section class="crm-card crm-panel-pad">
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>Usuario</th><th>Rol</th><th>Supervisor</th><th>Estado</th><th>Ultimo ingreso</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse ($this->users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td><strong>{{ $user->name }}</strong><br><span class="text-xs text-[var(--color-crm-muted)]">{{ $user->email }}</span></td>
                            <td>{{ $user->role?->name ?? '-' }}</td>
                            <td>{{ $user->supervisor?->name ?? '-' }}</td>
                            <td><span class="crm-badge {{ $user->status === 'active' ? 'crm-badge--success' : 'crm-badge--danger' }}">{{ UserStatus::tryFrom($user->status)?->label() ?? $user->status }}</span></td>
                            <td>{{ $user->last_login_at?->format('d/m/Y H:i') ?? '-' }}</td>
                            <td>
                                <div class="crm-actions">
                                    @can('users.edit')
                                        <flux:button size="sm" icon="pencil-square" wire:click="edit({{ $user->id }})">Editar</flux:button>
                                    @endcan
                                    @if ($user->status === UserStatus::Active->value)
                                        @can('users.disable')
                                            <flux:button size="sm" wire:click="disable({{ $user->id }})" wire:confirm="Desactivar este usuario?">Desactivar</flux:button>
                                        @endcan
                                    @else
                                        @can('users.reactivate')
                                            <flux:button size="sm" wire:click="reactivate({{ $user->id }})">Reactivar</flux:button>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="crm-empty">No hay usuarios con estos filtros.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
