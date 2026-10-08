<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Preferencias de apariencia')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">Preferencias de apariencia</flux:heading>

    <x-pages::settings.layout heading="Preferencias" subheading="Actualiza las preferencias visuales de tu cuenta">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">Claro</flux:radio>
            <flux:radio value="dark" icon="moon">Oscuro</flux:radio>
            <flux:radio value="system" icon="computer-desktop">Sistema</flux:radio>
        </flux:radio.group>
    </x-pages::settings.layout>
</section>
