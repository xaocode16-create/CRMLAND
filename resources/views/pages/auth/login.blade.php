<x-layouts::auth :title="__('Iniciar sesión')">
    <div class="crm-login-stack flex flex-col gap-6">
        <x-auth-header :title="__('Bienvenido')" :description="__('Accede a tu cuenta para continuar')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <div class="crm-auth-input">
                <i class="bi bi-person crm-auth-input-icon" aria-hidden="true"></i>
                <flux:input
                    name="email"
                    :label="__('Usuario o correo')"
                    :value="old('email')"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="correo@ejemplo.com"
                />
            </div>

            <!-- Password -->
            <div class="crm-password-field">
                <div class="crm-field-label-row">
                    <label for="password">{{ __('Contraseña') }}</label>
                </div>
                <div class="crm-auth-input">
                    <i class="bi bi-lock crm-auth-input-icon" aria-hidden="true"></i>
                    <flux:input
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        :placeholder="__('Contraseña')"
                        viewable
                    />
                </div>
            </div>

            <div class="crm-login-options">
                <flux:checkbox name="remember" :label="__('Recordarme')" :checked="old('remember')" />
                @if (Route::has('password.request'))
                    <flux:link :href="route('password.request')" wire:navigate>
                        {{ __('¿Olvidaste tu contraseña?') }}
                    </flux:link>
                @endif
            </div>

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                    {{ __('Iniciar sesión') }}
                </flux:button>
            </div>
        </form>

        <div class="crm-auth-divider"><span>o</span></div>

        <flux:button :href="route('register')" variant="outline" class="crm-register-button w-full" wire:navigate>
            <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
            {{ __('Crear una cuenta') }}
        </flux:button>

        <div class="crm-auth-security">
            <i class="bi bi-shield-check" aria-hidden="true"></i>
            <span>{{ __('Conexión segura y protegida con SSL') }}</span>
        </div>
    </div>
</x-layouts::auth>
