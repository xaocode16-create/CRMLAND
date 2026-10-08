<x-layouts::auth :title="__('Confirmar contrasena')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirmar contrasena')"
            :description="__('Esta es un area segura. Confirma tu contrasena para continuar.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('Contrasena')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Contrasena')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('Confirmar') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
