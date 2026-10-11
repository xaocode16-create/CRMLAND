<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="crm-body min-h-screen">
        @php
            $searchRoute = request()->routeIs('properties.*') ? 'properties.index' : 'leads.index';
            $currentPage = match (true) {
                request()->routeIs('profile.edit') => 'Mi perfil',
                request()->routeIs('security.edit') => 'Seguridad',
                request()->routeIs('appearance.edit') => 'Preferencias',
                request()->routeIs('dashboard') => 'Panel',
                request()->routeIs('leads.*') => 'Leads',
                request()->routeIs('properties.*') => 'Propiedades',
                request()->routeIs('notifications.*') => 'Notificaciones',
                request()->routeIs('pipeline.*') => 'Pipeline',
                request()->routeIs('follow-ups.*') => 'Seguimientos',
                request()->routeIs('calendar.*') => 'Calendario',
                request()->routeIs('reservations.*') => 'Reservas',
                request()->routeIs('sales.*') => 'Ventas',
                request()->routeIs('commissions.*') => 'Comisiones',
                request()->routeIs('reports.*') => 'Reportes',
                request()->routeIs('users.*') => 'Usuarios',
                request()->routeIs('roles.*') => 'Roles',
                request()->routeIs('permissions.*') => 'Permisos',
                request()->routeIs('assistant') => 'Asistente IA',
                default => 'Panel',
            };
        @endphp

        <header class="crm-shell-header">
            <a class="crm-shell-brand" href="{{ route('dashboard') }}" wire:navigate aria-label="CRMLAND, ir al panel">
                <span class="crm-shell-brand-mark" aria-hidden="true">
                    <img src="{{ asset('assets/img/logo.png') }}" alt="">
                </span>
                <span class="crm-shell-brand-copy">
                    <strong>CRM<span>LAND</span></strong>
                    <small>CRM Inmobiliario</small>
                </span>
            </a>

            <div class="crm-shell-header-content">
                <div class="crm-shell-toolbar">
                    <flux:sidebar.toggle
                        class="crm-mobile-sidebar-toggle"
                        icon="bars-2"
                        aria-label="Abrir navegación"
                    />

                    <form class="crm-global-search" action="{{ route($searchRoute) }}" method="GET" role="search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            name="search"
                            value="{{ request('search') }}"
                            placeholder="Buscar propiedades, leads, clientes..."
                            aria-label="Buscar {{ $searchRoute === 'properties.index' ? 'propiedades' : 'leads' }}"
                        >
                    </form>

                    <div class="crm-shell-actions">
                        <a
                            class="crm-header-ai {{ request()->routeIs('assistant') ? 'is-active' : '' }}"
                            href="{{ route('assistant') }}"
                            aria-label="Abrir Asistente IA"
                            wire:navigate
                        >
                            <i class="bi bi-stars" aria-hidden="true"></i>
                            <span>Asistente IA</span>
                        </a>

                        @if (collect($accountItems)->contains(fn ($item) => str_contains($item['href'], '/notifications')))
                            <a
                                class="crm-header-icon-link"
                                href="{{ route('notifications.index') }}"
                                title="Notificaciones"
                                aria-label="Notificaciones"
                                wire:navigate
                            >
                                <i class="bi bi-bell" aria-hidden="true"></i>
                            </a>
                        @else
                            <span
                                class="crm-header-icon-link is-disabled"
                                role="img"
                                aria-label="Notificaciones, no disponibles para este rol"
                                title="Notificaciones no disponibles para este rol"
                            >
                                <i class="bi bi-bell" aria-hidden="true"></i>
                            </span>
                        @endif

                        <a
                            class="crm-header-icon-link crm-header-help"
                            href="{{ route('profile.edit') }}"
                            title="Ayuda y perfil"
                            aria-label="Ayuda y perfil"
                            wire:navigate
                        >
                            <i class="bi bi-question-circle" aria-hidden="true"></i>
                        </a>

                        <x-desktop-user-menu :account-items="$accountItems" />
                    </div>
                </div>
            </div>
        </header>

        <div class="crm-workspace">
            <flux:sidebar sticky collapsible="mobile" class="crm-sidebar">
                <flux:sidebar.nav>
                    @foreach ($navGroups as $heading => $items)
                        <flux:sidebar.group :heading="$heading" class="grid">
                            @if ($heading === 'Administración' && auth()->user()?->isAdministrador())
                                @php($activeRoleName = auth()->user()?->role?->name ?? 'Administrador')

                                <div class="crm-sidebar-role-context" aria-label="Rol activo">
                                    <span class="crm-sidebar-role-context__label">Rol activo</span>
                                    <strong>
                                        <i class="crm-sidebar-role-context__icon bi bi-shield-lock" aria-hidden="true"></i>
                                        {{ $activeRoleName }}
                                    </strong>
                                </div>
                            @endif

                            @foreach ($items as $item)
                                <flux:sidebar.item
                                    :href="$item['href']"
                                    :current="request()->routeIs($item['active'])"
                                    wire:navigate
                                >
                                    <span class="crm-nav-link">
                                        <i class="crm-nav-icon bi {{ $item['icon'] }}" aria-hidden="true"></i>
                                        <span>{{ $item['label'] }}</span>
                                    </span>
                                </flux:sidebar.item>
                            @endforeach
                        </flux:sidebar.group>
                    @endforeach
                </flux:sidebar.nav>

                <flux:spacer />
            </flux:sidebar>

            <div class="crm-workspace-content">
                <nav class="crm-shell-breadcrumb" aria-label="Ruta de navegación">
                    <i class="bi bi-house-door crm-breadcrumb-home" aria-hidden="true"></i>
                    <a href="{{ route('dashboard') }}" wire:navigate>Inicio</a>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    <span aria-current="page">{{ $currentPage }}</span>
                </nav>

                {{ $slot }}
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
