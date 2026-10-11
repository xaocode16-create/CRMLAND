<?php

use App\Enums\FollowUpStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Asistente IA')] class extends Component
{
    public string $message = '';

    public bool $showSuggestions = true;

    /** @var array<int, array{type: string, text: string, time: string}> */
    public array $conversation = [];

    public function mount(): void
    {
        $this->currentUser()->loadMissing('role.permissions');
        $this->conversation = [[
            'type' => 'assistant',
            'text' => $this->welcomeMessage(),
            'time' => now()->format('H:i'),
        ]];
    }

    public function sendMessage(): void
    {
        $text = trim($this->message);

        if ($text === '') {
            return;
        }

        $this->conversation[] = [
            'type' => 'user',
            'text' => $text,
            'time' => now()->format('H:i'),
        ];
        $this->conversation[] = [
            'type' => 'assistant',
            'text' => $this->assistantReply($text),
            'time' => now()->format('H:i'),
        ];
        $this->message = '';
    }

    public function askSuggestion(string $prompt): void
    {
        $this->showSuggestions = false;
        $this->message = $prompt;
        $this->sendMessage();
    }

    public function clearConversation(): void
    {
        $this->showSuggestions = true;
        $this->conversation = [[
            'type' => 'assistant',
            'text' => $this->welcomeMessage(),
            'time' => now()->format('H:i'),
        ]];
    }

    #[Computed]
    public function roleContext(): array
    {
        $user = $this->currentUser();
        $slug = $user->roleSlug() ?? UserRole::Asesor->value;
        $name = $user->role?->name ?? match ($slug) {
            UserRole::Administrador->value => 'Administrador',
            UserRole::Supervisor->value => 'Supervisor',
            default => 'Asesor',
        };

        return match ($slug) {
            UserRole::Administrador->value => [
                'slug' => $slug,
                'name' => $name,
                'description' => 'Centro inteligente para análisis, consultas y acciones administrativas.',
                'scope' => 'El asistente tiene acceso a la información global del CRM según tus permisos.',
                'permission_status' => 'Acceso completo',
                'permission_copy' => 'Puede leer y analizar toda la información del CRM, incluyendo usuarios, ventas, propiedades, leads, reservas, comisiones y configuración global.',
                'icon' => 'bi-shield-check',
            ],
            UserRole::Supervisor->value => [
                'slug' => $slug,
                'name' => $name,
                'description' => 'Centro inteligente para analizar el rendimiento de tu equipo comercial.',
                'scope' => 'El asistente consulta la información de tu equipo y tus permisos operativos.',
                'permission_status' => 'Acceso de equipo',
                'permission_copy' => 'Puede analizar leads, pipeline, ventas, reservas y comisiones dentro del alcance de tu equipo.',
                'icon' => 'bi-people',
            ],
            default => [
                'slug' => UserRole::Asesor->value,
                'name' => $name,
                'description' => 'Centro inteligente para organizar tus oportunidades y actividades comerciales.',
                'scope' => 'El asistente solo consulta la información asignada a tu usuario.',
                'permission_status' => 'Acceso personal',
                'permission_copy' => 'Puede ayudarte con tus leads, seguimientos, propiedades, reservas, ventas y comisiones asignadas.',
                'icon' => 'bi-person-badge',
            ],
        };
    }

    #[Computed]
    public function metrics(): array
    {
        $user = $this->currentUser();
        $now = now();
        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();

        $criticalAlerts = Reservation::query()->visibleFor($user)
            ->where('requires_approval', true)
            ->where('approval_status', 'PENDING')
            ->count();
        $newLeads = Lead::query()->visibleFor($user)
            ->whereBetween('entered_at', [$start, $end])
            ->count();
        $monthlySales = Sale::query()->visibleFor($user)
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->whereBetween('sold_at', [$start, $end])
            ->sum('price');
        $pendingTasks = FollowUp::query()->visibleFor($user)
            ->where('status', FollowUpStatus::Pending->value)
            ->count();

        return [
            [
                'label' => 'Alertas críticas',
                'value' => number_format($criticalAlerts),
                'trend' => $criticalAlerts > 0 ? 'Revisar ahora' : 'Todo en orden',
                'icon' => 'bi-exclamation-circle-fill',
                'tone' => 'red',
                'href' => route('notifications.index'),
                'link' => 'Ver detalles',
            ],
            [
                'label' => 'Leads nuevos',
                'value' => number_format($newLeads),
                'trend' => 'Este mes',
                'icon' => 'bi-people-fill',
                'tone' => 'blue',
                'href' => route('leads.index'),
                'link' => 'Ver leads',
            ],
            [
                'label' => 'Ventas del mes',
                'value' => 'S/ '.number_format((float) $monthlySales, 0),
                'trend' => 'Acumulado actual',
                'icon' => 'bi-house-fill',
                'tone' => 'green',
                'href' => route('sales.index'),
                'link' => 'Ver ventas',
            ],
            [
                'label' => 'Tareas pendientes',
                'value' => number_format($pendingTasks),
                'trend' => 'Seguimientos abiertos',
                'icon' => 'bi-calendar2-check-fill',
                'tone' => 'orange',
                'href' => route('follow-ups.index'),
                'link' => 'Ver tareas',
            ],
        ];
    }

    #[Computed]
    public function quickActions(): array
    {
        $isAdmin = $this->currentUser()->isAdministrador();
        $isSupervisor = $this->currentUser()->isSupervisor();

        if ($isAdmin) {
            return [
                ['label' => 'Reportes y análisis', 'icon' => 'bi-bar-chart-line-fill', 'tone' => 'purple', 'href' => route('reports.index')],
                ['label' => 'Usuarios', 'icon' => 'bi-people-fill', 'tone' => 'blue', 'href' => route('users.index')],
                ['label' => 'Roles', 'icon' => 'bi-person-vcard-fill', 'tone' => 'pink', 'href' => route('roles.index')],
                ['label' => 'Permisos', 'icon' => 'bi-shield-lock-fill', 'tone' => 'blue', 'href' => route('permissions.index')],
                ['label' => 'Configuración global', 'icon' => 'bi-gear-fill', 'tone' => 'blue', 'href' => route('settings.index')],
                ['label' => 'Ventas', 'icon' => 'bi-house-fill', 'tone' => 'green', 'href' => route('sales.index')],
                ['label' => 'Reservas', 'icon' => 'bi-calendar2-week-fill', 'tone' => 'orange', 'href' => route('reservations.index')],
                ['label' => 'Propiedades', 'icon' => 'bi-buildings-fill', 'tone' => 'blue', 'href' => route('properties.index')],
                ['label' => 'Comisiones', 'icon' => 'bi-stack', 'tone' => 'purple', 'href' => route('commissions.index')],
            ];
        }

        return [
            ['label' => 'Reportes y análisis', 'icon' => 'bi-bar-chart-line-fill', 'tone' => 'purple', 'href' => route('reports.index')],
            ['label' => $isSupervisor ? 'Mi equipo' : 'Mis leads', 'icon' => 'bi-people-fill', 'tone' => 'blue', 'href' => route('leads.index')],
            ['label' => 'Pipeline', 'icon' => 'bi-kanban-fill', 'tone' => 'pink', 'href' => route('pipeline.index')],
            ['label' => 'Seguimientos', 'icon' => 'bi-clipboard-check-fill', 'tone' => 'blue', 'href' => route('follow-ups.index')],
            ['label' => 'Ventas', 'icon' => 'bi-house-fill', 'tone' => 'green', 'href' => route('sales.index')],
            ['label' => 'Reservas', 'icon' => 'bi-calendar2-week-fill', 'tone' => 'orange', 'href' => route('reservations.index')],
            ['label' => 'Propiedades', 'icon' => 'bi-buildings-fill', 'tone' => 'blue', 'href' => route('properties.index')],
            ['label' => 'Calendario', 'icon' => 'bi-calendar3', 'tone' => 'purple', 'href' => route('calendar.index')],
            ['label' => 'Comisiones', 'icon' => 'bi-stack', 'tone' => 'purple', 'href' => route('commissions.index')],
        ];
    }

    #[Computed]
    public function suggestions(): array
    {
        $user = $this->currentUser();
        $noFollowUp = Lead::query()->visibleFor($user)
            ->where('status', 'active')
            ->whereNull('next_follow_up_at')
            ->count();

        return [
            ['label' => 'Genera un reporte de ventas del mes', 'icon' => 'bi-bar-chart-line-fill', 'tone' => 'purple'],
            ['label' => 'Resume las reservas por vencer', 'icon' => 'bi-calendar2-week-fill', 'tone' => 'orange'],
            ['label' => 'Muéstrame leads sin seguimiento', 'icon' => 'bi-person-exclamation', 'tone' => 'blue'],
            ['label' => 'Analiza comisiones pendientes', 'icon' => 'bi-currency-dollar', 'tone' => 'blue'],
            ['label' => 'Revisa usuarios inactivos', 'icon' => 'bi-person-fill', 'tone' => 'blue'],
            ['label' => 'Detecta cuellos de botella del pipeline', 'icon' => 'bi-bar-chart-line-fill', 'tone' => 'purple'],
        ];
    }

    #[Computed]
    public function recommendations(): array
    {
        $user = $this->currentUser();
        $noFollowUp = Lead::query()->visibleFor($user)
            ->where('status', 'active')
            ->whereNull('next_follow_up_at')
            ->count();
        $inactiveUsers = User::query()->where('status', UserStatus::Inactive->value)->count();
        $monthlySales = Sale::query()->visibleFor($user)
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->whereBetween('sold_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('price');

        return [
            [
                'title' => 'Incrementar seguimiento',
                'description' => $noFollowUp.' leads activos sin próxima actividad. Considera asignarles un asesor.',
                'icon' => 'bi-bar-chart-line-fill',
                'tone' => 'green',
            ],
            [
                'title' => 'Usuarios inactivos',
                'description' => $inactiveUsers.' usuarios no han iniciado sesión recientemente.',
                'icon' => 'bi-exclamation-triangle-fill',
                'tone' => 'orange',
            ],
            [
                'title' => 'Oportunidad en ventas',
                'description' => 'Las ventas del mes acumulan S/ '.number_format((float) $monthlySales, 0).'. ¡Buen trabajo!',
                'icon' => 'bi-bar-chart-line-fill',
                'tone' => 'purple',
            ],
        ];
    }

    #[Computed]
    public function recentHistory(): array
    {
        return [
            ['label' => 'Reporte de ventas del mes', 'time' => 'Hoy, 10:24', 'icon' => 'bi-bar-chart-line-fill', 'tone' => 'purple'],
            ['label' => 'Usuarios inactivos en el sistema', 'time' => 'Ayer, 16:30', 'icon' => 'bi-people-fill', 'tone' => 'blue'],
            ['label' => 'Análisis de comisiones pendientes', 'time' => 'Ayer, 11:15', 'icon' => 'bi-currency-dollar', 'tone' => 'green'],
            ['label' => 'Leads sin seguimiento', 'time' => '12 may. 2024', 'icon' => 'bi-person-fill', 'tone' => 'blue'],
            ['label' => 'Resumen de reservas por vencer', 'time' => '10 may. 2024', 'icon' => 'bi-calendar2-week-fill', 'tone' => 'orange'],
        ];
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function welcomeMessage(): string
    {
        return match ($this->currentUser()->roleSlug()) {
            UserRole::Administrador->value => 'Soy tu asistente IA de CRMLAND para administradores. Puedo ayudarte con reportes, análisis de ventas, gestión de usuarios, permisos, propiedades, leads, reservas, comisiones y configuración global. También puedo identificar oportunidades, detectar problemas y sugerirte acciones para que tu operación sea más eficiente. ¿En qué te puedo ayudar hoy?',
            UserRole::Supervisor->value => 'Soy tu asistente IA de CRMLAND para supervisores. Puedo ayudarte a analizar el rendimiento del equipo, el pipeline, las reservas, las ventas y las comisiones dentro de tu alcance. ¿En qué te puedo ayudar hoy?',
            default => 'Soy tu asistente IA de CRMLAND. Puedo ayudarte a organizar tus leads, seguimientos, propiedades, reservas, ventas y comisiones asignadas. ¿En qué te puedo ayudar hoy?',
        };
    }

    private function assistantReply(string $message): string
    {
        $lower = mb_strtolower($message);

        if (str_contains($lower, 'venta')) {
            return 'Estoy preparando el resumen comercial con las ventas registradas del periodo actual y las oportunidades relacionadas. Puedes abrir el módulo de Ventas para revisar el detalle.';
        }

        if (str_contains($lower, 'lead') || str_contains($lower, 'seguimiento')) {
            return 'Puedo ayudarte a priorizar los leads sin próxima actividad y sugerir el siguiente contacto. La vista de Leads contiene el detalle de cada oportunidad.';
        }

        if (str_contains($lower, 'usuario') || str_contains($lower, 'permiso') || str_contains($lower, 'rol')) {
            return 'Puedo revisar el estado de usuarios, roles y permisos disponibles según tu alcance. Usa las acciones rápidas para abrir el módulo correspondiente.';
        }

        return 'Entendido. Puedo analizar la información disponible en tu CRM y orientarte hacia el módulo adecuado. Prueba una de las sugerencias rápidas para comenzar.';
    }
};
?>

<section class="crm-page crm-assistant-page">
    <header class="crm-card crm-assistant-heading">
        <div class="crm-assistant-heading__title">
            <span class="crm-assistant-heading__icon"><i class="bi bi-stars" aria-hidden="true"></i></span>
            <div>
                <h1 class="crm-module-title">Asistente IA</h1>
                <p class="crm-module-description">{{ $this->roleContext['description'] }}</p>
            </div>
        </div>
        <div class="crm-assistant-heading__scope">
            <span class="crm-assistant-role-pill"><i class="bi {{ $this->roleContext['icon'] }}" aria-hidden="true"></i> Rol activo: <strong>{{ $this->roleContext['name'] }}</strong></span>
            <p>{{ $this->roleContext['scope'] }}</p>
        </div>
    </header>

    <div class="crm-assistant-metrics">
        @foreach ($this->metrics as $metric)
            <a class="crm-card crm-assistant-metric is-{{ $metric['tone'] }}" href="{{ $metric['href'] }}" wire:navigate wire:key="assistant-metric-{{ $metric['label'] }}">
                <span class="crm-assistant-metric__icon"><i class="bi {{ $metric['icon'] }}" aria-hidden="true"></i></span>
                <span class="crm-assistant-metric__copy"><small>{{ $metric['label'] }}</small><strong>{{ $metric['value'] }}</strong></span>
                <span class="crm-assistant-metric__meta"><em>{{ $metric['trend'] }}</em><b>{{ $metric['link'] }} <i class="bi bi-arrow-right"></i></b></span>
            </a>
        @endforeach
    </div>

    <div class="crm-assistant-layout">
        <div class="crm-assistant-main-column">
            <section class="crm-card crm-assistant-chat-card">
                <div class="crm-assistant-panel-heading">
                    <h2>Chat con tu asistente IA</h2>
                    <div class="crm-assistant-panel-actions">
                        <button type="button" wire:click="clearConversation" title="Reiniciar conversación" aria-label="Reiniciar conversación"><i class="bi bi-clock-history"></i></button>
                        <button type="button" wire:click="clearConversation" title="Limpiar chat" aria-label="Limpiar chat"><i class="bi bi-trash3"></i></button>
                        <button type="button" title="Expandir chat" aria-label="Expandir chat"><i class="bi bi-arrows-angle-expand"></i></button>
                    </div>
                </div>

                <div class="crm-assistant-conversation" aria-live="polite">
                    @foreach ($conversation as $entry)
                        <div class="crm-assistant-message is-{{ $entry['type'] }}" wire:key="assistant-message-{{ $loop->index }}">
                            <span class="crm-assistant-message__avatar"><i class="bi {{ $entry['type'] === 'assistant' ? 'bi-robot' : 'bi-person-fill' }}" aria-hidden="true"></i></span>
                            <div class="crm-assistant-message__body">
                                @if ($entry['type'] === 'assistant')
                                    <strong>{{ $this->roleContext['name'] === 'Administrador' ? 'Asistente IA' : 'Asistente IA · '.$this->roleContext['name'] }}</strong>
                                @endif
                                <p>{{ $entry['text'] }}</p>
                                <small>{{ $entry['time'] }}</small>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($showSuggestions)
                    <div class="crm-assistant-suggestions">
                        <h3>Sugerencias rápidas</h3>
                        <div class="crm-assistant-suggestion-grid">
                            @foreach ($this->suggestions as $suggestion)
                                <button type="button" wire:click="askSuggestion('{{ addslashes($suggestion['label']) }}')" wire:key="assistant-suggestion-{{ $loop->index }}">
                                    <span class="is-{{ $suggestion['tone'] }}"><i class="bi {{ $suggestion['icon'] }}" aria-hidden="true"></i></span>
                                    <span>{{ $suggestion['label'] }}</span>
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <form class="crm-assistant-composer" wire:submit="sendMessage">
                    <input type="text" wire:model.live="message" placeholder="Escribe tu mensaje aquí..." aria-label="Mensaje para el asistente IA">
                    <button type="submit" aria-label="Enviar mensaje"><i class="bi bi-send-fill"></i></button>
                </form>
                <p class="crm-assistant-disclaimer"><i class="bi bi-info-circle-fill" aria-hidden="true"></i> El asistente puede usar la información global del CRM para darte respuestas más precisas y realizar acciones según tus permisos.</p>
            </section>

            <section class="crm-card crm-assistant-recommendations">
                <div class="crm-assistant-panel-heading">
                    <h2><i class="bi bi-lightbulb-fill" aria-hidden="true"></i> Recomendaciones del día</h2>
                    <span><i class="bi bi-stars" aria-hidden="true"></i> Generadas por IA</span>
                </div>
                <div class="crm-assistant-recommendation-grid">
                    @foreach ($this->recommendations as $recommendation)
                        <article class="crm-assistant-recommendation is-{{ $recommendation['tone'] }}" wire:key="assistant-recommendation-{{ $loop->index }}">
                            <span><i class="bi {{ $recommendation['icon'] }}" aria-hidden="true"></i></span>
                            <div><strong>{{ $recommendation['title'] }}</strong><p>{{ $recommendation['description'] }}</p></div>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </article>
                    @endforeach
                </div>
            </section>
        </div>

        <aside class="crm-assistant-side-column">
            <section class="crm-card crm-assistant-actions-card">
                <div class="crm-assistant-panel-heading"><h2>Acciones rápidas</h2><a href="{{ route('dashboard') }}" wire:navigate>Ver todas <i class="bi bi-arrow-right"></i></a></div>
                <div class="crm-assistant-action-grid">
                    @foreach ($this->quickActions as $action)
                        <a class="crm-assistant-action is-{{ $action['tone'] }}" href="{{ $action['href'] }}" wire:navigate wire:key="assistant-action-{{ $loop->index }}">
                            <i class="bi {{ $action['icon'] }}" aria-hidden="true"></i><span>{{ $action['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="crm-card crm-assistant-permissions-card">
                <div class="crm-assistant-permissions-icon"><i class="bi bi-lock-fill" aria-hidden="true"></i></div>
                <div class="crm-assistant-panel-heading"><h2>Permisos del asistente</h2><span><i class="bi bi-check-circle-fill"></i> {{ $this->roleContext['permission_status'] }}</span></div>
                <p>{{ $this->roleContext['permission_copy'] }}</p>
            </section>

            <section class="crm-card crm-assistant-history-card">
                <div class="crm-assistant-panel-heading"><h2>Historial reciente</h2><button type="button" wire:click="clearConversation">Ver todo <i class="bi bi-arrow-right"></i></button></div>
                <div class="crm-assistant-history-list">
                    @foreach ($this->recentHistory as $item)
                        <div class="crm-assistant-history-row" wire:key="assistant-history-{{ $loop->index }}">
                            <span class="is-{{ $item['tone'] }}"><i class="bi {{ $item['icon'] }}" aria-hidden="true"></i></span><strong>{{ $item['label'] }}</strong><time>{{ $item['time'] }}</time><i class="bi bi-three-dots"></i>
                        </div>
                    @endforeach
                </div>
            </section>
        </aside>
    </div>
</section>
