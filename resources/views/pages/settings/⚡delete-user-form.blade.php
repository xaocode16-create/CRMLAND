<?php

use Livewire\Component;

new class extends Component {}; ?>

<section class="crm-configuracion-section-02">
    <div class="crm-configuracion-div-15">
        <flux:heading>Eliminar cuenta</flux:heading>
        <flux:subheading>Elimina tu cuenta y todos sus recursos</flux:subheading>
    </div>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" data-test="delete-user-button">
            Eliminar cuenta
        </flux:button>
    </flux:modal.trigger>

    <livewire:pages::settings.delete-user-modal />
</section>
