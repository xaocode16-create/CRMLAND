<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="crm-body min-h-screen">
        <flux:header container class="crm-header">
            <flux:sidebar.toggle class="mr-2 lg:hidden" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    Dashboard
                </flux:navbar.item>
                <flux:navbar.item icon="users" href="#" aria-disabled="true" class="opacity-55">
                    Leads
                </flux:navbar.item>
                <flux:navbar.item icon="building-office" href="#" aria-disabled="true" class="opacity-55">
                    Propiedades
                </flux:navbar.item>
                <flux:navbar.item icon="calendar-days" href="#" aria-disabled="true" class="opacity-55">
                    Seguimientos
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <x-desktop-user-menu />
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
