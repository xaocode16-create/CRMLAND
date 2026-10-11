<?php

use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public bool $requiresConfirmation;

    #[Locked]
    public string $qrCodeSvg = '';

    #[Locked]
    public string $manualSetupKey = '';

    public bool $showVerificationStep = false;

    public bool $setupComplete = false;

    #[Validate('required|string|size:6', onUpdate: false)]
    public string $code = '';

    /**
     * Mount the component.
     */
    public function mount(bool $requiresConfirmation): void
    {
        $this->requiresConfirmation = $requiresConfirmation;
    }

    #[On('start-two-factor-setup')]
    public function startTwoFactorSetup(): void
    {
        $enableTwoFactorAuthentication = app(EnableTwoFactorAuthentication::class);
        $enableTwoFactorAuthentication(auth()->user());

        $this->loadSetupData();
    }

    /**
     * Load the two-factor authentication setup data for the user.
     */
    private function loadSetupData(): void
    {
        $user = auth()->user()?->fresh();

        try {
            if (! $user || ! $user->two_factor_secret) {
                throw new Exception('Two-factor setup secret is not available.');
            }

            $this->qrCodeSvg = $user->twoFactorQrCodeSvg();
            $this->manualSetupKey = decrypt($user->two_factor_secret);
        } catch (Exception) {
            $this->addError('setupData', 'Failed to fetch setup data.');

            $this->reset('qrCodeSvg', 'manualSetupKey');
        }
    }

    /**
     * Show the two-factor verification step if necessary.
     */
    public function showVerificationIfNecessary(): void
    {
        if ($this->requiresConfirmation) {
            $this->showVerificationStep = true;

            $this->resetErrorBag();

            return;
        }

        $this->closeModal();
        $this->dispatch('two-factor-enabled');
    }

    /**
     * Confirm two-factor authentication for the user.
     */
    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirmTwoFactorAuthentication): void
    {
        $this->validate();

        $confirmTwoFactorAuthentication(auth()->user(), $this->code);

        $this->setupComplete = true;

        $this->closeModal();

        $this->dispatch('two-factor-enabled');
    }

    /**
     * Reset two-factor verification state.
     */
    public function resetVerification(): void
    {
        $this->reset('code', 'showVerificationStep');

        $this->resetErrorBag();
    }

    /**
     * Close the two-factor authentication modal.
     */
    public function closeModal(): void
    {
        $this->reset(
            'code',
            'manualSetupKey',
            'qrCodeSvg',
            'showVerificationStep',
            'setupComplete',
        );

        $this->resetErrorBag();
    }

    /**
     * Get the current modal configuration state.
     */
    #[Computed]
    public function modalConfig(): array
    {
        if ($this->setupComplete) {
            return [
                'title' => 'Autenticacion de dos factores activada',
                'description' => 'La autenticacion de dos factores esta activada. Escanea el codigo QR o ingresa la clave en tu aplicacion de autenticacion.',
                'buttonText' => 'Cerrar',
            ];
        }

        if ($this->showVerificationStep) {
            return [
                'title' => 'Verificar codigo de autenticacion',
                'description' => 'Ingresa el codigo de 6 digitos de tu aplicacion de autenticacion.',
                'buttonText' => 'Continuar',
            ];
        }

        return [
            'title' => 'Activar autenticacion de dos factores',
            'description' => 'Para terminar, escanea el codigo QR o ingresa la clave en tu aplicacion de autenticacion.',
            'buttonText' => 'Continuar',
        ];
    }
}; ?>

<flux:modal
    name="two-factor-setup-modal"
    class="md:min-w-md crm-configuracion-flux-modal-01"
    @close="closeModal"
>
        <div class="crm-configuracion-form-01">
            <div class="crm-configuracion-div-30">
                <div class="dark:border-stone-600 dark:bg-stone-800 crm-configuracion-div-31">
                    <div class="dark:border-stone-600 dark:bg-stone-200 crm-configuracion-div-32">
                        <div class="[&>div]:flex-1 dark:divide-stone-300 crm-configuracion-div-33">
                            @for ($i = 1; $i <= 5; $i++)
                                <div></div>
                            @endfor
                        </div>

                        <div class="[&>div]:flex-1 dark:divide-stone-300 crm-configuracion-div-34">
                            @for ($i = 1; $i <= 5; $i++)
                                <div></div>
                            @endfor
                        </div>

                        <flux:icon.qr-code class="dark:text-accent-foreground crm-configuracion-flux-icon-04"/>
                    </div>
                </div>

                <div class="crm-configuracion-div-35">
                    <flux:heading size="lg">{{ $this->modalConfig['title'] }}</flux:heading>
                    <flux:text>{{ $this->modalConfig['description'] }}</flux:text>
                </div>
            </div>

            @if ($showVerificationStep)
                <div class="crm-configuracion-form-01">
                    <div
                        class="crm-configuracion-div-36"
                        x-data
                        x-init="$nextTick(() => $el.querySelector('input')?.focus())"
                    >
                        <flux:otp
                            name="code"
                            wire:model="code"
                            length="6"
                            label="Codigo de autenticacion"
                            label:sr-only
                            class="crm-configuracion-flux-otp-01"
                        />
                    </div>

                    <div class="crm-configuracion-div-37">
                        <flux:button
                            variant="outline"
                            class="crm-configuracion-flux-button-02"
                            wire:click="resetVerification"
                        >
                            Volver
                        </flux:button>

                        <flux:button
                            variant="primary"
                            class="crm-configuracion-flux-button-02"
                            wire:click="confirmTwoFactor"
                            x-bind:disabled="$wire.code.length < 6"
                        >
                            Confirmar
                        </flux:button>
                    </div>
                </div>
            @else
                @error('setupData')
                    <flux:callout variant="danger" icon="x-circle" heading="{{ $message }}"/>
                @enderror

                <div class="crm-configuracion-div-38">
                    <div class="dark:border-stone-700 crm-configuracion-div-39">
                        @empty($qrCodeSvg)
                            <div class="dark:bg-stone-700 crm-configuracion-div-40">
                                <flux:icon.loading/>
                            </div>
                        @else
                            <div x-data class="crm-configuracion-div-41">
                                <div
                                    class="crm-configuracion-div-42"
                                    :style="($flux.appearance === 'dark' || ($flux.appearance === 'system' && $flux.dark)) ? 'filter: invert(1) brightness(1.5)' : ''"
                                >
                                    {!! $qrCodeSvg !!}
                                </div>
                            </div>
                        @endempty
                    </div>
                </div>

                <div>
                    <flux:button
                        :disabled="$errors->has('setupData')"
                        variant="primary"
                        class="crm-configuracion-section-01"
                        wire:click="showVerificationIfNecessary"
                    >
                        {{ $this->modalConfig['buttonText'] }}
                    </flux:button>
                </div>

                <div class="crm-form-stack">
                    <div class="crm-configuracion-div-43">
                        <div class="dark:bg-stone-600 crm-configuracion-div-44"></div>
                        <span class="dark:bg-stone-800 dark:text-stone-400 crm-configuracion-span-03">
                            o ingresa el codigo manualmente
                        </span>
                    </div>

                    <div
                        class="crm-configuracion-div-45"
                        x-data="{
                            copied: false,
                            async copy() {
                                try {
                                    await navigator.clipboard.writeText('{{ $manualSetupKey }}');
                                    this.copied = true;
                                    setTimeout(() => this.copied = false, 1500);
                                } catch (e) {
                                    console.warn('Could not copy to clipboard');
                                }
                            }
                        }"
                    >
                        <div class="dark:border-stone-700 crm-configuracion-div-46">
                            @empty($manualSetupKey)
                                <div class="dark:bg-stone-700 crm-configuracion-div-47">
                                    <flux:icon.loading variant="mini"/>
                                </div>
                            @else
                                <input
                                    type="text"
                                    readonly
                                    value="{{ $manualSetupKey }}"
                                    class="dark:text-stone-100 crm-configuracion-input-01"
                                />

                                <button
                                    @click="copy()"
                                    class="dark:border-stone-600 crm-configuracion-button-01"
                                >
                                    <flux:icon.document-duplicate x-show="!copied" variant="outline"></flux:icon>
                                    <flux:icon.check
                                        x-show="copied"
                                        variant="solid"
                                        class="crm-configuracion-flux-icon-05"
                                    ></flux:icon>
                                </button>
                            @endempty
                        </div>
                    </div>
                </div>
            @endif
        </div>
</flux:modal>
