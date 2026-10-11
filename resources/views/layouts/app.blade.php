<x-layouts::app.sidebar :title="$title ?? null">
    <main class="crm-main">
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
