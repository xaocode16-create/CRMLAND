<?php

use App\Models\Permission;
use App\Models\Role;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Roles')] class extends Component
{
    public ?int $selectedRoleId = null;
    public array $permissionIds = [];

    public function mount(): void
    {
        Gate::authorize('roles.view');
        $this->selectedRoleId = Role::query()->orderBy('id')->value('id');
        $this->loadRolePermissions();
    }

    public function selectRole(int $roleId): void
    {
        Gate::authorize('roles.view');
        $this->selectedRoleId = $roleId;
        $this->loadRolePermissions();
    }

    public function save(): void
    {
        Gate::authorize('roles.edit');

        $role = Role::query()->findOrFail($this->selectedRoleId);
        $role->permissions()->sync(
            collect($this->permissionIds)
                ->mapWithKeys(fn (int|string $permissionId): array => [
                    (int) $permissionId => ['created_at' => now()],
                ])
                ->all(),
        );

        Flux::toast(variant: 'success', text: 'Permisos del rol actualizados.');
    }

    #[Computed]
    public function roles(): \Illuminate\Support\Collection
    {
        return Role::query()
            ->withCount('users')
            ->with('permissions')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function selectedRole(): ?Role
    {
        return $this->selectedRoleId
            ? Role::query()->with('permissions')->find($this->selectedRoleId)
            : null;
    }

    #[Computed]
    public function permissionsByModule(): \Illuminate\Support\Collection
    {
        return Permission::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module');
    }

    private function loadRolePermissions(): void
    {
        $this->permissionIds = $this->selectedRoleId
            ? Role::query()->findOrFail($this->selectedRoleId)->permissions()->pluck('permissions.id')->map(fn (int $id): string => (string) $id)->all()
            : [];
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Administracion</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Roles</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Perfiles de acceso del equipo comercial.</p>
        </div>
        <div class="crm-actions">
            <flux:button :href="route('permissions.index')" icon="shield-check" wire:navigate>Permisos</flux:button>
        </div>
    </header>

    <div class="grid gap-4 xl:grid-cols-[340px_1fr]">
        <section class="crm-card crm-panel-pad">
            <p class="crm-eyebrow">Roles disponibles</p>
            <div class="mt-4 space-y-3">
                @foreach ($this->roles as $role)
                    <button
                        type="button"
                        wire:key="role-{{ $role->id }}"
                        wire:click="selectRole({{ $role->id }})"
                        class="w-full rounded-lg border px-4 py-3 text-left transition {{ $selectedRoleId === $role->id ? 'border-[var(--color-crm-blue)] bg-[var(--color-crm-bg-soft)]' : 'border-[var(--color-crm-border)] bg-white' }}"
                    >
                        <strong class="block text-[var(--color-crm-navy)]">{{ $role->name }}</strong>
                        <span class="text-sm text-[var(--color-crm-muted)]">{{ $role->permissions->count() }} permisos / {{ $role->users_count }} usuarios</span>
                    </button>
                @endforeach
            </div>
        </section>

        <section class="crm-card crm-panel-pad">
            @if ($this->selectedRole)
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="crm-eyebrow">{{ $this->selectedRole->slug }}</p>
                        <h2 class="text-lg font-bold text-[var(--color-crm-navy)]">{{ $this->selectedRole->name }}</h2>
                    </div>
                    @can('roles.edit')
                        <flux:button variant="primary" icon="check" wire:click="save">Guardar permisos</flux:button>
                    @endcan
                </div>

                <div class="space-y-5">
                    @foreach ($this->permissionsByModule as $module => $permissions)
                        <section wire:key="module-{{ $module }}">
                            <h3 class="mb-3 text-sm font-bold uppercase text-[var(--color-crm-muted)]">{{ $module }}</h3>
                            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($permissions as $permission)
                                    <label class="flex items-start gap-3 rounded-lg border border-[var(--color-crm-border)] bg-white p-3 text-sm" wire:key="permission-{{ $permission->id }}">
                                        <input class="mt-1" type="checkbox" value="{{ $permission->id }}" wire:model="permissionIds">
                                        <span>
                                            <strong class="block text-[var(--color-crm-navy)]">{{ $permission->name }}</strong>
                                            <span class="text-xs text-[var(--color-crm-muted)]">{{ $permission->slug }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            @else
                <div class="crm-empty">No hay roles configurados.</div>
            @endif
        </section>
    </div>
</section>
