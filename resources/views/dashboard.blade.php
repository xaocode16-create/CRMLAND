<x-layouts::app title="Panel">
    <div class="crm-page">
        <section class="crm-page-header">
            <div>
                <p class="crm-eyebrow">CRM Inmobiliario</p>
                <h1 class="text-2xl font-bold text-[#10244a]">Dashboard</h1>
                <p class="mt-1 text-sm text-crm-muted">Resumen comercial del dia.</p>
            </div>
            <div class="hidden items-center gap-3 text-sm text-crm-muted sm:flex">
                <img src="{{ asset('assets/img/logo.png') }}" alt="CRMLAND" class="h-12 w-12 rounded-full bg-white object-contain shadow-sm">
                <span class="font-semibold text-crm-blue">CRMLAND</span>
            </div>
        </section>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <article class="crm-card crm-stat">
                <span>Total de leads</span>
                <strong>0</strong>
                <p class="mt-2 text-sm text-crm-muted">Sin datos cargados.</p>
            </article>

            <article class="crm-card crm-stat">
                <span>Propiedades activas</span>
                <strong>0</strong>
                <p class="mt-2 text-sm text-crm-muted">Sin datos cargados.</p>
            </article>

            <article class="crm-card crm-stat">
                <span>Seguimientos de hoy</span>
                <strong>0</strong>
                <p class="mt-2 text-sm text-crm-muted">Sin datos cargados.</p>
            </article>
        </div>

        <div class="crm-card min-h-[22rem] overflow-hidden">
            <div class="grid gap-4 p-5 lg:grid-cols-[1.1fr_0.9fr]">
                <div>
                    <p class="crm-eyebrow">Actividad reciente</p>
                    <h2 class="mt-1 text-xl font-bold text-[#10244a]">Movimiento comercial</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-crm-muted">
                        Todavia no hay actividad registrada para mostrar.
                    </p>
                </div>

                <div class="rounded-lg border border-crm-border bg-crm-bg-soft p-4">
                    <p class="text-sm font-semibold text-crm-text">Resumen de equipo</p>
                    <div class="mt-3 grid gap-2 text-sm text-crm-muted">
                        <span>Leads asignados: 0</span>
                        <span>Citas programadas: 0</span>
                        <span>Ventas registradas: 0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts::app>
