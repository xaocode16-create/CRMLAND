@props(['title', 'description', 'icon'])

<section {{ $attributes->class(['crm-profile-card']) }}>
    <header class="crm-profile-card-heading">
        <div class="crm-profile-heading-title">
            <span class="crm-profile-panel-icon"><i class="bi {{ $icon }}" aria-hidden="true"></i></span>
            <div>
                <h2>{{ $title }}</h2>
                <p>{{ $description }}</p>
            </div>
        </div>
        {{ $actions ?? '' }}
    </header>
    {{ $slot }}
</section>
