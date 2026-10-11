<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="crm-configuracion-elemento-01">
    <form method="POST" wire:submit="deleteUser" class="crm-configuracion-form-01">
        <div>
            <flux:heading size="lg">¿Estas seguro de que deseas eliminar tu cuenta?</flux:heading>

            <flux:subheading>
                Al eliminar tu cuenta, todos sus recursos y datos se borraran permanentemente. Ingresa tu contrasena para confirmar.
            </flux:subheading>
        </div>

        <flux:input wire:model="password" label="Contrasena" type="password" viewable />

        <div class="rtl:space-x-reverse crm-configuracion-div-16">
            <flux:modal.close>
                <flux:button variant="filled">Cancelar</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                Eliminar cuenta
            </flux:button>
        </div>
    </form>
</flux:modal>
