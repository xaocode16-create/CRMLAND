<x-layouts::auth :title="__('Restablecer contrasena')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Restablecer contrasena')" :description="__('Ingresa tu nueva contrasena a continuacion')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <flux:input
                name="email"
                value="{{ request('email') }}"
                :label="__('Correo electronico')"
                type="email"
                required
                autocomplete="email"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Contrasena')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Contrasena')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirmar contrasena')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirmar contrasena')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="reset-password-button">
                    {{ __('Restablecer contrasena') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
