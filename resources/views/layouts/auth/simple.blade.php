<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="crm-auth-body antialiased">
        <div class="crm-auth-layout min-h-svh">
            <aside class="crm-auth-aside hidden min-w-0 flex-col lg:flex">
                <div class="crm-auth-aside-top">
                    <a href="{{ route('home') }}" class="crm-auth-brand flex w-fit items-center gap-4 text-white" wire:navigate>
                        <span class="crm-brand-mark flex size-16 items-center justify-center">
                            <x-app-logo-icon class="size-14" />
                        </span>
                        <span>
                            <span class="crm-auth-brand-name block text-3xl font-extrabold tracking-wide">CRML<span>AND</span></span>
                            <span class="block text-base text-[#d6e4f7]">CRM Inmobiliario</span>
                        </span>
                    </a>

                    <nav class="crm-auth-nav" aria-label="Navegacion informativa">
                        <span>INMUEBLES</span><i></i><span>PERSONAS</span><i></i><span>OPORTUNIDADES</span><b></b>
                    </nav>
                </div>

                <div class="crm-auth-hero-copy max-w-xl">
                    <p class="crm-eyebrow text-[#dce9fa]">Tu CRM inmobiliario</p>
                    <h1 class="mt-3 font-extrabold text-white">
                        Convierte oportunidades en grandes <span>historias</span>
                    </h1>
                    <p class="mt-6 max-w-lg text-lg leading-8 text-[#e4efff]">
                        Gestiona tu equipo comercial, propiedades, clientes y ventas desde un solo lugar.
                    </p>
                </div>

                <div class="crm-auth-features grid grid-cols-4 text-center text-sm text-[#b0c5db]">
                    <div><i class="bi bi-house-door crm-feature-icon" aria-hidden="true"></i><strong>Propiedades</strong><span>Organiza y publica</span></div>
                    <div><i class="bi bi-people crm-feature-icon" aria-hidden="true"></i><strong>Clientes</strong><span>Seguimiento total</span></div>
                    <div><i class="bi bi-bar-chart-line crm-feature-icon" aria-hidden="true"></i><strong>Ventas</strong><span>Más resultados</span></div>
                    <div><i class="bi bi-check2-square crm-feature-icon" aria-hidden="true"></i><strong>Equipos</strong><span>Trabajo eficiente</span></div>
                </div>

                <p class="crm-auth-footer"><span>CRMLAND</span><i></i><span>Impulsando el futuro inmobiliario</span></p>
            </aside>

            <main class="crm-auth-main crm-auth-surface flex min-w-0 items-center justify-center">
                <div class="crm-auth-card">
                    <a href="{{ route('home') }}" class="mb-6 flex items-center gap-3 text-white lg:hidden" wire:navigate>
                        <span class="crm-brand-mark flex size-14 items-center justify-center">
                            <x-app-logo-icon class="size-12" />
                        </span>
                        <span class="crm-auth-brand-name text-2xl font-extrabold">CRML<span>AND</span></span>
                    </a>

                    {{ $slot }}
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
