@props(['accountItems' => []])

@php($user = auth()->user())

<flux:dropdown position="bottom" align="end">
    <flux:button
        variant="ghost"
        class="crm-user-trigger"
        aria-label="Abrir menú de usuario"
        data-test="global-user-menu-button"
    >
        <span class="crm-user-avatar" aria-hidden="true">
            @if ($user->avatar)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($user->avatar) }}" alt="">
            @else
                <span>{{ $user->initials() }}</span>
            @endif
        </span>
        <span class="crm-user-trigger-copy">
            <strong>{{ $user->name }}</strong>
            <small>{{ $user->email }}</small>
        </span>
        <flux:icon name="chevron-down" class="crm-user-chevron" />
    </flux:button>

    <flux:menu>
        <div class="crm-user-menu-heading">
            <strong>{{ $user->name }}</strong>
            <span>{{ $user->email }}</span>
        </div>

        <flux:menu.separator />

        <flux:menu.radio.group>
            @foreach ($accountItems as $item)
                <flux:menu.item :href="$item['href']" wire:navigate>
                    <i class="bi {{ $item['icon'] }} mr-2" aria-hidden="true"></i>
                    {{ $item['label'] }}
                </flux:menu.item>
            @endforeach
        </flux:menu.radio.group>

        <flux:menu.separator />

        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item
                as="button"
                type="submit"
                icon="arrow-right-start-on-rectangle"
                class="w-full cursor-pointer"
                data-test="logout-button"
            >
                Cerrar sesión
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
