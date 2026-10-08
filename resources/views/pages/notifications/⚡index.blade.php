<?php

use App\Models\CrmNotification;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notificaciones')] class extends Component
{
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('notifications.view');
    }

    public function markRead(int $notificationId): void
    {
        CrmNotification::query()
            ->where('user_id', Auth::id())
            ->whereKey($notificationId)
            ->update([
                'status' => 'READ',
                'read_at' => now(),
            ]);

        Flux::toast(variant: 'success', text: 'Notificacion marcada como leida.');
    }

    public function markAllRead(): void
    {
        CrmNotification::query()
            ->where('user_id', Auth::id())
            ->where('status', 'UNREAD')
            ->update([
                'status' => 'READ',
                'read_at' => now(),
            ]);

        Flux::toast(variant: 'success', text: 'Notificaciones actualizadas.');
    }

    #[Computed]
    public function notifications(): \Illuminate\Support\Collection
    {
        return CrmNotification::query()
            ->with('lead')
            ->where('user_id', Auth::id())
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest('created_at')
            ->limit(150)
            ->get();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return CrmNotification::query()
            ->where('user_id', Auth::id())
            ->where('status', 'UNREAD')
            ->count();
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Administracion</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Notificaciones</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">{{ $this->unreadCount }} notificacion(es) pendiente(s).</p>
        </div>
        <div class="crm-actions">
            <flux:select wire:model.live="status" label="Estado">
                <option value="">Todas</option>
                <option value="UNREAD">No leidas</option>
                <option value="READ">Leidas</option>
            </flux:select>
            <flux:button icon="check-circle" wire:click="markAllRead">Marcar todo leido</flux:button>
        </div>
    </header>

    <section class="space-y-3">
        @forelse ($this->notifications as $notification)
            <article class="crm-card crm-panel-pad" wire:key="notification-{{ $notification->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="crm-eyebrow">{{ $notification->type }}</p>
                        <h2 class="text-lg font-bold text-[var(--color-crm-navy)]">{{ $notification->title }}</h2>
                        <p class="mt-2 text-sm text-[var(--color-crm-muted)]">{{ $notification->body }}</p>
                        @if ($notification->lead)
                            <a class="mt-2 inline-block font-semibold text-[var(--color-crm-blue)]" href="{{ route('leads.show', $notification->lead) }}" wire:navigate>{{ $notification->lead->full_name }}</a>
                        @endif
                    </div>
                    <div class="crm-actions">
                        <span class="crm-badge {{ $notification->status === 'UNREAD' ? 'crm-badge--warning' : 'crm-badge--success' }}">{{ $notification->status === 'UNREAD' ? 'No leida' : 'Leida' }}</span>
                        @if ($notification->status === 'UNREAD')
                            <flux:button size="sm" wire:click="markRead({{ $notification->id }})">Marcar leida</flux:button>
                        @endif
                    </div>
                </div>
                <p class="mt-3 text-xs text-[var(--color-crm-muted)]">{{ $notification->created_at?->format('d/m/Y H:i') }}</p>
            </article>
        @empty
            <section class="crm-card crm-empty">No hay notificaciones.</section>
        @endforelse
    </section>
</section>
