<?php

use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public array $recoveryCodes = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadRecoveryCodes();
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $this->recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}; ?>

<div
    class="dark:border-white/10 crm-configuracion-div-05"
    wire:cloak
    x-data="{ showRecoveryCodes: false }"
>
    <div class="crm-configuracion-div-06">
        <div class="crm-configuracion-div-07">
            <flux:icon.lock-closed variant="outline" class="crm-configuracion-flux-icon-01"/>
                <flux:heading size="lg" level="3">Codigos de recuperacion</flux:heading>
        </div>
        <flux:text variant="subtle">
            Los codigos de recuperacion te permiten recuperar el acceso si pierdes tu dispositivo. Guardalos en un administrador de contrasenas seguro.
        </flux:text>
    </div>

    <div class="crm-configuracion-div-08">
        <div class="sm:flex-row sm:items-center sm:justify-between crm-configuracion-div-09">
            <flux:button
                x-show="!showRecoveryCodes"
                icon="eye"
                icon:variant="outline"
                variant="primary"
                @click="showRecoveryCodes = true;"
                aria-expanded="false"
                aria-controls="recovery-codes-section"
            >
                Ver codigos de recuperacion
            </flux:button>

            <flux:button
                x-show="showRecoveryCodes"
                icon="eye-slash"
                icon:variant="outline"
                variant="primary"
                @click="showRecoveryCodes = false"
                aria-expanded="true"
                aria-controls="recovery-codes-section"
            >
                Ocultar codigos de recuperacion
            </flux:button>

            @if (filled($recoveryCodes))
                <flux:button
                    x-show="showRecoveryCodes"
                    icon="arrow-path"
                    variant="filled"
                    wire:click="regenerateRecoveryCodes"
                >
                    Regenerar codigos
                </flux:button>
            @endif
        </div>

        <div
            x-show="showRecoveryCodes"
            x-transition
            id="recovery-codes-section"
            class="crm-configuracion-div-10"
            x-bind:aria-hidden="!showRecoveryCodes"
        >
            <div class="crm-configuracion-div-11">
                @error('recoveryCodes')
                    <flux:callout variant="danger" icon="x-circle" heading="{{$message}}"/>
                @enderror

                @if (filled($recoveryCodes))
                    <div
                        class="dark:bg-white/5 crm-configuracion-div-12"
                        role="list"
                        aria-label="Codigos de recuperacion"
                    >
                        @foreach($recoveryCodes as $code)
                            <div
                                role="listitem"
                                class="crm-configuracion-div-13"
                                wire:loading.class="crm-configuracion-div-14"
                            >
                                {{ $code }}
                            </div>
                        @endforeach
                    </div>
                    <flux:text variant="subtle" class="crm-configuracion-flux-text-01">
                        Cada codigo de recuperacion puede usarse una sola vez y se eliminara despues. Si necesitas mas, haz clic en Regenerar codigos.
                    </flux:text>
                @endif
            </div>
        </div>
    </div>
</div>
