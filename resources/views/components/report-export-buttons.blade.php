@props(['report'])

<div {{ $attributes->class(['crm-report-export-actions']) }} role="group" aria-label="Exportar {{ $report->label() }}">
    @foreach (App\Enums\ReportFormat::cases() as $format)
        <flux:button
            size="sm"
            variant="outline"
            :icon="$format->icon()"
            wire:click="exportReport('{{ $report->value }}', '{{ $format->value }}')"
            wire:loading.attr="disabled"
            wire:target="exportReport, from, to"
            :title="'Exportar '.$report->label().' a '.$format->label()"
            :aria-label="'Exportar '.$report->label().' a '.$format->label()"
            :data-test="'export-'.$report->value.'-'.$format->value"
            :class="'crm-report-export-button is-'.$format->value"
            wire:key="export-{{ $report->value }}-{{ $format->value }}"
        >{{ $format->label() }}</flux:button>
    @endforeach
</div>
