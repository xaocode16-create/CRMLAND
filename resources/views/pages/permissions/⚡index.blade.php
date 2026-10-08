<?php

use App\Models\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Permisos')] class extends Component
{
    public function mount(): void
    {
        Gate::authorize('permissions.view');
    }

    #[Computed]
    public function permissionsByModule(): \Illuminate\Support\Collection
    {
        return Permission::query()
            ->withCount('roles')
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module');
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Administracion</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Permisos</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Catalogo canónico de permisos usado por Gates y Policies.</p>
        </div>
        <div class="crm-actions">
            <flux:button :href="route('roles.index')" icon="key" wire:navigate>Roles</flux:button>
        </div>
    </header>

    <section class="grid gap-4 lg:grid-cols-2">
        @foreach ($this->permissionsByModule as $module => $permissions)
            <article class="crm-card crm-panel-pad" wire:key="permission-module-{{ $module }}">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <p class="crm-eyebrow">Modulo</p>
                        <h2 class="text-lg font-bold text-[var(--color-crm-navy)]">{{ $module }}</h2>
                    </div>
                    <span class="crm-badge crm-badge--info">{{ $permissions->count() }} permiso(s)</span>
                </div>
                <div class="space-y-2">
                    @foreach ($permissions as $permission)
                        <div class="rounded-lg border border-[var(--color-crm-border)] bg-white p-3" wire:key="permission-{{ $permission->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <strong class="block">{{ $permission->name }}</strong>
                                    <span class="text-xs text-[var(--color-crm-muted)]">{{ $permission->slug }}</span>
                                </div>
                                <span class="crm-badge">{{ $permission->roles_count }} rol(es)</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>
</section>
