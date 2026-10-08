@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'CRMLAND')" {{ $attributes }}>
        <x-slot name="logo" class="crm-brand-mark flex aspect-square size-11 items-center justify-center">
            <x-app-logo-icon class="size-10" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'CRMLAND')" {{ $attributes }}>
        <x-slot name="logo" class="crm-brand-mark flex aspect-square size-11 items-center justify-center">
            <x-app-logo-icon class="size-10" />
        </x-slot>
    </flux:brand>
@endif
