<div class="max-md:flex-col crm-configuracion-div-01">
    <div class="md:w-[220px] crm-configuracion-div-02">
        <flux:navlist aria-label="Configuracion">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>Perfil</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>Seguridad</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>Preferencias</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="max-md:pt-6 crm-configuracion-div-03">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="crm-configuracion-div-04">
            {{ $slot }}
        </div>
    </div>
</div>
