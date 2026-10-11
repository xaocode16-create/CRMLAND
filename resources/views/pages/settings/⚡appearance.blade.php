<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Preferencias')] class extends Component
{
    public string $theme = 'light';

    public string $language = 'es-PE';

    public string $timezone = 'America/Lima';

    public string $dateFormat = 'DD/MM/YYYY';

    public string $timeFormat = '24h';

    public string $currency = 'PEN';

    public string $homePage = 'dashboard';

    public string $leadView = 'table';

    public string $tableDensity = 'standard';

    public string $interfaceSize = 'standard';

    public bool $showMetrics = true;

    public bool $reduceAnimations = false;

    public bool $highContrast = false;

    public bool $confirmSensitiveActions = true;

    public array $notificationPreferences = [
        'email' => true,
        'push' => true,
        'followups' => true,
        'reservations' => true,
        'daily_summary' => false,
    ];

    public function mount(): void
    {
        $this->loadPreferences();
    }

    public function setTheme(string $theme): void
    {
        if (in_array($theme, ['light', 'dark', 'system'], true)) {
            $this->theme = $theme;
        }
    }

    public function toggleNotification(string $key): void
    {
        if (array_key_exists($key, $this->notificationPreferences)) {
            $this->notificationPreferences[$key] = ! $this->notificationPreferences[$key];
        }
    }

    public function resetPreferences(): void
    {
        $this->theme = 'light';
        $this->language = 'es-PE';
        $this->timezone = 'America/Lima';
        $this->dateFormat = 'DD/MM/YYYY';
        $this->timeFormat = '24h';
        $this->currency = 'PEN';
        $this->homePage = 'dashboard';
        $this->leadView = 'table';
        $this->tableDensity = 'standard';
        $this->interfaceSize = 'standard';
        $this->showMetrics = true;
        $this->reduceAnimations = false;
        $this->highContrast = false;
        $this->confirmSensitiveActions = true;
        $this->notificationPreferences = [
            'email' => true,
            'push' => true,
            'followups' => true,
            'reservations' => true,
            'daily_summary' => false,
        ];

        Flux::toast(variant: 'success', text: 'Valores predeterminados restaurados. Guarda los cambios para aplicarlos.');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'theme' => ['required', 'in:light,dark,system'],
            'language' => ['required', 'in:es-PE,es-ES,en-US'],
            'timezone' => ['required', 'in:America/Lima,America/Bogota,Europe/Madrid,UTC'],
            'dateFormat' => ['required', 'in:DD/MM/YYYY,MM/DD/YYYY,YYYY-MM-DD'],
            'timeFormat' => ['required', 'in:12h,24h'],
            'currency' => ['required', 'in:PEN,USD,EUR'],
            'homePage' => ['required', 'in:dashboard,leads,pipeline,calendar,reports'],
            'leadView' => ['required', 'in:table,kanban'],
            'tableDensity' => ['required', 'in:compact,standard,comfortable'],
            'interfaceSize' => ['required', 'in:small,standard,large'],
            'showMetrics' => ['boolean'],
            'reduceAnimations' => ['boolean'],
            'highContrast' => ['boolean'],
            'confirmSensitiveActions' => ['boolean'],
            'notificationPreferences' => ['array'],
            'notificationPreferences.*' => ['boolean'],
        ]);

        $user = Auth::user();
        $user->forceFill([
            'preferences' => [
                'theme' => $validated['theme'],
                'language' => $validated['language'],
                'timezone' => $validated['timezone'],
                'date_format' => $validated['dateFormat'],
                'time_format' => $validated['timeFormat'],
                'currency' => $validated['currency'],
                'home_page' => $validated['homePage'],
                'lead_view' => $validated['leadView'],
                'table_density' => $validated['tableDensity'],
                'interface_size' => $validated['interfaceSize'],
                'show_metrics' => $validated['showMetrics'],
                'reduce_animations' => $validated['reduceAnimations'],
                'high_contrast' => $validated['highContrast'],
                'confirm_sensitive_actions' => $validated['confirmSensitiveActions'],
                'notifications' => $validated['notificationPreferences'],
            ],
        ])->save();

        Flux::toast(variant: 'success', text: 'Preferencias guardadas correctamente.');
    }

    #[Computed]
    public function roleInfo(): array
    {
        $user = Auth::user();
        $user->loadMissing('role.permissions');

        return match (true) {
            $user->isAdministrador() => ['name' => 'Administrador', 'description' => 'Control total del CRM y configuración global disponible.', 'icon' => 'bi-shield-check', 'tone' => 'blue'],
            $user->isSupervisor() => ['name' => 'Supervisor', 'description' => 'Gestiona la actividad y el rendimiento de su equipo.', 'icon' => 'bi-people', 'tone' => 'purple'],
            default => ['name' => 'Asesor', 'description' => 'Gestiona leads, clientes y oportunidades asignadas.', 'icon' => 'bi-person', 'tone' => 'green'],
        };
    }

    #[Computed]
    public function homePageOptions(): array
    {
        $user = Auth::user();
        $options = [['value' => 'dashboard', 'label' => $user->isAsesor() ? 'Mi panel comercial' : ($user->isSupervisor() ? 'Panel de equipo' : 'Panel principal'), 'icon' => 'bi-house']];

        if ($user->hasPermission('leads.view')) {
            $options[] = ['value' => 'leads', 'label' => $user->isAsesor() ? 'Mis leads' : 'Leads', 'icon' => 'bi-people'];
        }

        if ($user->hasPermission('pipeline.view')) {
            $options[] = ['value' => 'pipeline', 'label' => 'Pipeline', 'icon' => 'bi-kanban'];
        }

        if ($user->hasPermission('appointments.view')) {
            $options[] = ['value' => 'calendar', 'label' => 'Calendario', 'icon' => 'bi-calendar3'];
        }

        if ($user->hasPermission('reports.view')) {
            $options[] = ['value' => 'reports', 'label' => $user->isAsesor() ? 'Mis reportes' : 'Reportes', 'icon' => 'bi-bar-chart'];
        }

        return $options;
    }

    #[Computed]
    public function accessibleModules(): array
    {
        $user = Auth::user();

        return collect([
            ['permission' => 'dashboard.view', 'label' => 'Panel', 'icon' => 'bi-speedometer2'],
            ['permission' => 'leads.view', 'label' => $user->isAsesor() ? 'Mis leads' : 'Leads', 'icon' => 'bi-people'],
            ['permission' => 'pipeline.view', 'label' => 'Pipeline', 'icon' => 'bi-kanban'],
            ['permission' => 'properties.view', 'label' => 'Propiedades', 'icon' => 'bi-buildings'],
            ['permission' => 'followups.view', 'label' => 'Seguimientos', 'icon' => 'bi-clipboard-check'],
            ['permission' => 'appointments.view', 'label' => 'Calendario', 'icon' => 'bi-calendar3'],
            ['permission' => 'sales.view', 'label' => 'Ventas', 'icon' => 'bi-cash-stack'],
            ['permission' => 'reports.view', 'label' => $user->isAsesor() ? 'Mis reportes' : 'Reportes', 'icon' => 'bi-bar-chart'],
            ['permission' => 'users.view', 'label' => 'Usuarios', 'icon' => 'bi-person-gear'],
            ['permission' => 'settings.edit', 'label' => 'Configuración global', 'icon' => 'bi-gear'],
        ])->filter(fn (array $module): bool => $user->hasPermission($module['permission']))->values()->all();
    }

    public function preferenceValue(string $key, mixed $default = null): mixed
    {
        return Auth::user()->preferences[$key] ?? $default;
    }

    private function loadPreferences(): void
    {
        $preferences = Auth::user()->preferences ?? [];
        $notifications = $preferences['notifications'] ?? [];

        $this->theme = $preferences['theme'] ?? 'light';
        $this->language = $preferences['language'] ?? 'es-PE';
        $this->timezone = $preferences['timezone'] ?? 'America/Lima';
        $this->dateFormat = $preferences['date_format'] ?? 'DD/MM/YYYY';
        $this->timeFormat = $preferences['time_format'] ?? '24h';
        $this->currency = $preferences['currency'] ?? 'PEN';
        $this->homePage = $preferences['home_page'] ?? 'dashboard';
        $this->leadView = $preferences['lead_view'] ?? 'table';
        $this->tableDensity = $preferences['table_density'] ?? 'standard';
        $this->interfaceSize = $preferences['interface_size'] ?? 'standard';
        $this->showMetrics = (bool) ($preferences['show_metrics'] ?? true);
        $this->reduceAnimations = (bool) ($preferences['reduce_animations'] ?? false);
        $this->highContrast = (bool) ($preferences['high_contrast'] ?? false);
        $this->confirmSensitiveActions = (bool) ($preferences['confirm_sensitive_actions'] ?? true);
        $this->notificationPreferences = array_merge($this->notificationPreferences, $notifications);
    }
}; ?>

<section class="crm-preferences-page">
    <header class="crm-preferences-header">
        <div>
            <p class="crm-eyebrow">Mi cuenta</p>
            <h1>Preferencias</h1>
            <p>Personaliza tu cuenta y la experiencia de tu espacio de trabajo en CRMLAND.</p>
        </div>
        <span class="crm-preferences-account-badge"><i class="bi {{ $this->roleInfo['icon'] }}"></i>{{ $this->roleInfo['name'] }}</span>
    </header>

    <nav class="crm-preferences-tabs" aria-label="Configuración de cuenta">
        <a href="{{ route('profile.edit') }}" wire:navigate><i class="bi bi-person"></i><span><strong>Perfil</strong><small>Información personal</small></span></a>
        <a href="{{ route('security.edit') }}" wire:navigate><i class="bi bi-shield-check"></i><span><strong>Seguridad</strong><small>Contraseña y acceso</small></span></a>
        <a href="{{ route('appearance.edit') }}" class="is-active" wire:navigate><i class="bi bi-sliders"></i><span><strong>Preferencias</strong><small>Apariencia y sistema</small></span></a>
    </nav>

    <form id="preferences-form" wire:submit="save" class="crm-preferences-content">
        <main class="crm-preferences-main">
            <section class="crm-preferences-card">
                <div class="crm-preferences-section-title"><span class="is-blue"><i class="bi bi-palette"></i></span><div><h2>Apariencia</h2><p>Elige el tema visual de la plataforma según tu preferencia.</p></div></div>
                <div class="crm-preferences-theme-grid">
                    @foreach ([['value' => 'light', 'label' => 'Claro', 'icon' => 'bi-sun'], ['value' => 'dark', 'label' => 'Oscuro', 'icon' => 'bi-moon'], ['value' => 'system', 'label' => 'Sistema', 'icon' => 'bi-display']] as $themeOption)
                        <button type="button" class="{{ $theme === $themeOption['value'] ? 'is-active' : '' }}" wire:click="setTheme('{{ $themeOption['value'] }}')" x-on:click="$flux.appearance = '{{ $themeOption['value'] }}'"><i class="bi {{ $themeOption['icon'] }}"></i><strong>{{ $themeOption['label'] }}</strong>@if ($theme === $themeOption['value'])<span><i class="bi bi-check-lg"></i></span>@endif</button>
                    @endforeach
                </div>
            </section>

            <section class="crm-preferences-card">
                <div class="crm-preferences-section-title"><span class="is-blue"><i class="bi bi-globe2"></i></span><div><h2>Idioma y región</h2><p>Configura el idioma, la zona horaria y los formatos de visualización.</p></div></div>
                <div class="crm-preferences-form-grid">
                    <label><span>Idioma</span><select wire:model="language"><option value="es-PE">🇵🇪 Español (Perú)</option><option value="es-ES">🇪🇸 Español (España)</option><option value="en-US">🇺🇸 English (United States)</option></select></label>
                    <label><span>Zona horaria</span><select wire:model="timezone"><option value="America/Lima">(GMT-5) Lima, Perú</option><option value="America/Bogota">(GMT-5) Bogotá, Colombia</option><option value="Europe/Madrid">(GMT+1) Madrid, España</option><option value="UTC">(GMT+0) UTC</option></select></label>
                    <label><span>Formato de fecha</span><select wire:model="dateFormat"><option value="DD/MM/YYYY">DD/MM/YYYY (31/01/2024)</option><option value="MM/DD/YYYY">MM/DD/YYYY (01/31/2024)</option><option value="YYYY-MM-DD">YYYY-MM-DD (2024-01-31)</option></select></label>
                    <label><span>Formato de hora</span><select wire:model="timeFormat"><option value="24h">24 horas (14:30)</option><option value="12h">12 horas (02:30 PM)</option></select></label>
                    <label><span>Moneda</span><select wire:model="currency"><option value="PEN">Sol peruano (S/)</option><option value="USD">Dólar estadounidense (US$)</option><option value="EUR">Euro (€)</option></select></label>
                </div>
            </section>

            <section class="crm-preferences-card">
                <div class="crm-preferences-section-title"><span class="is-blue"><i class="bi bi-bell"></i></span><div><h2>Notificaciones</h2><p>Elige qué notificaciones quieres recibir y por qué canales.</p></div></div>
                <div class="crm-preferences-toggle-list">
                    @foreach ([['key' => 'email', 'label' => 'Correo electrónico', 'description' => 'Recibe actualizaciones importantes por email.', 'icon' => 'bi-envelope'], ['key' => 'push', 'label' => 'Notificaciones push', 'description' => 'Recibe notificaciones en tiempo real en tu navegador.', 'icon' => 'bi-phone'], ['key' => 'followups', 'label' => 'Recordatorios de seguimientos', 'description' => 'Te avisaremos de seguimientos programados.', 'icon' => 'bi-calendar-check'], ['key' => 'reservations', 'label' => 'Alertas de reservas', 'description' => 'Notificaciones sobre nuevas reservas y cambios.', 'icon' => 'bi-bell'], ['key' => 'daily_summary', 'label' => 'Resumen diario', 'description' => 'Recibe un resumen diario de la actividad de tu cuenta.', 'icon' => 'bi-bar-chart']] as $notificationOption)
                        <div><i class="bi {{ $notificationOption['icon'] }}"></i><span><strong>{{ $notificationOption['label'] }}</strong><small>{{ $notificationOption['description'] }}</small></span><button type="button" class="crm-preferences-switch {{ $notificationPreferences[$notificationOption['key']] ? 'is-on' : '' }}" wire:click="toggleNotification('{{ $notificationOption['key'] }}')" aria-label="Cambiar {{ $notificationOption['label'] }}"><span></span></button></div>
                    @endforeach
                </div>
            </section>

            <div class="crm-preferences-two-column">
                <section class="crm-preferences-card">
                    <div class="crm-preferences-section-title"><span class="is-purple"><i class="bi bi-grid"></i></span><div><h2>Preferencias del panel</h2><p>Personaliza la vista de tu panel y la información predeterminada.</p></div></div>
                    <div class="crm-preferences-form-grid is-compact">
                        <label><span>Página de inicio</span><select wire:model="homePage">@foreach ($this->homePageOptions as $option)<option value="{{ $option['value'] }}">{{ $option['label'] }}</option>@endforeach</select></label>
                        <label><span>Vista predeterminada de leads</span><select wire:model="leadView"><option value="table">Vista en tabla</option><option value="kanban">Vista kanban</option></select></label>
                        <label class="span-two"><span>Densidad de tablas</span><select wire:model="tableDensity"><option value="compact">Compacta</option><option value="standard">Estándar</option><option value="comfortable">Cómoda</option></select></label>
                    </div>
                    <div class="crm-preferences-inline-toggle"><span><i class="bi bi-bar-chart"></i><span><strong>Mostrar métricas rápidas</strong><small>Ver resumen de métricas en el panel principal.</small></span></span><button type="button" class="crm-preferences-switch {{ $showMetrics ? 'is-on' : '' }}" wire:click="$toggle('showMetrics')"><span></span></button></div>
                </section>

                <section class="crm-preferences-card">
                    <div class="crm-preferences-section-title"><span class="is-cyan"><i class="bi bi-universal-access"></i></span><div><h2>Accesibilidad y experiencia</h2><p>Ajusta la interfaz para una mejor experiencia de uso.</p></div></div>
                    <div class="crm-preferences-accessibility-list">
                        <div><i class="bi bi-stars"></i><span><strong>Reducir animaciones</strong><small>Disminuye las animaciones para una navegación más rápida.</small></span><button type="button" class="crm-preferences-switch {{ $reduceAnimations ? 'is-on' : '' }}" wire:click="$toggle('reduceAnimations')"><span></span></button></div>
                        <div><i class="bi bi-circle-half"></i><span><strong>Alto contraste</strong><small>Aumenta el contraste de la interfaz.</small></span><button type="button" class="crm-preferences-switch {{ $highContrast ? 'is-on' : '' }}" wire:click="$toggle('highContrast')"><span></span></button></div>
                        <label><i class="bi bi-fonts"></i><span><strong>Tamaño de interfaz</strong><small>Ajusta el tamaño del texto y elementos.</small></span><select wire:model="interfaceSize"><option value="small">Pequeño</option><option value="standard">Estándar</option><option value="large">Grande</option></select></label>
                        <div><i class="bi bi-shield-check"></i><span><strong>Confirmar acciones sensibles</strong><small>Mostrar confirmación en acciones importantes.</small></span><button type="button" class="crm-preferences-switch {{ $confirmSensitiveActions ? 'is-on' : '' }}" wire:click="$toggle('confirmSensitiveActions')"><span></span></button></div>
                    </div>
                </section>
            </div>
        </main>

        <aside class="crm-preferences-sidebar">
            <section class="crm-preferences-card crm-preferences-preview-card"><div class="crm-preferences-side-heading"><span class="is-blue"><i class="bi bi-eye"></i></span><div><h2>Vista previa de preferencias</h2><p>Así se verá tu experiencia con la configuración actual.</p></div></div><div class="crm-preferences-preview"><div class="crm-preferences-preview-top"><span><i class="bi bi-house"></i> CRMLAND</span><i class="bi bi-three-dots"></i></div><div class="crm-preferences-preview-body"><div class="crm-preferences-preview-nav"><i class="bi bi-grid"></i><i class="bi bi-people"></i><i class="bi bi-calendar"></i><i class="bi bi-bar-chart"></i></div><div class="crm-preferences-preview-dashboard"><span></span><div><i class="bi bi-buildings"></i><i class="bi bi-bar-chart-line"></i></div><div><i class="bi bi-person"></i><i class="bi bi-cash-stack"></i><i class="bi bi-calendar-check"></i></div></div></div></div></section>\n+            <section class="crm-preferences-card crm-preferences-summary-card"><div class=\"crm-preferences-summary-row\"><i class=\"bi bi-sun\"></i><span><strong>Tema {{ $theme === 'dark' ? 'oscuro' : ($theme === 'system' ? 'del sistema' : 'claro') }}</strong><small>Interfaz {{ $interfaceSize }}</small></span></div><div class=\"crm-preferences-summary-row\"><i class=\"bi bi-globe2\"></i><span><strong>{{ $language === 'en-US' ? 'English' : 'Español' }}</strong><small>Zona horaria: {{ str_replace('_', ' ', $timezone) }}</small></span></div><div class=\"crm-preferences-summary-row\"><i class=\"bi bi-bell\"></i><span><strong>Notificaciones {{ collect($notificationPreferences)->filter()->count() > 0 ? 'activadas' : 'pausadas' }}</strong><small>{{ collect($notificationPreferences)->filter()->count() }} canales activos</small></span></div><div class=\"crm-preferences-summary-row\"><i class=\"bi bi-grid\"></i><span><strong>Panel personalizado</strong><small>Inicio: {{ collect($this->homePageOptions)->firstWhere('value', $homePage)['label'] ?? 'Panel principal' }}</small></span></div><div class=\"crm-preferences-summary-row\"><i class=\"bi bi-person-badge\"></i><span><strong>{{ $this->roleInfo['name'] }}</strong><small>{{ count($this->accessibleModules) }} módulos disponibles</small></span></div></section>\n+            <section class=\"crm-preferences-card crm-preferences-modules-card\"><div class=\"crm-preferences-side-heading\"><span class=\"is-purple\"><i class=\"bi bi-grid-3x3-gap\"></i></span><div><h2>Accesos de tu rol</h2><p>Información disponible según tus permisos.</p></div></div><div class=\"crm-preferences-module-list\">@foreach ($this->accessibleModules as $module)<span><i class=\"bi {{ $module['icon'] }}\"></i>{{ $module['label'] }}</span>@endforeach</div>@if (Auth::user()->hasPermission('settings.edit'))<a href=\"{{ route('settings.index') }}\" wire:navigate>Administrar configuración global <i class=\"bi bi-arrow-right\"></i></a>@endif</section>\n+            <section class=\"crm-preferences-tip\"><i class=\"bi bi-lightbulb\"></i><div><strong>Consejo</strong><p>Estas preferencias se aplican solo a tu cuenta y no afectan a otros usuarios del equipo.</p></div></section>\n+        </aside>\n+    </form>\n+\n+    <footer class=\"crm-preferences-actions\"><button type=\"button\" class=\"crm-preferences-reset\" wire:click=\"resetPreferences\"><i class=\"bi bi-arrow-counterclockwise\"></i> Restablecer valores</button><span>Recupera la configuración predeterminada de todas las preferencias.</span><div><a href=\"{{ route('dashboard') }}\" class=\"crm-preferences-cancel\" wire:navigate>Cancelar</a><button type=\"submit\" class=\"crm-preferences-save\" form=\"preferences-form\"><i class=\"bi bi-lock-fill\"></i> Guardar cambios</button></div></footer>
</section>
