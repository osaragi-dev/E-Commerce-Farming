@php
    $mapsUrl = $companyProfile->mapsUrl();
    $whatsappUrl = $companyProfile->whatsappUrl();
    $instagramUrl = $companyProfile->instagramUrl();
@endphp

<footer class="site-footer">
    <div class="wrap footer-top">
        <div class="footer-brand-contact">
            <a href="{{ route('home') }}" class="site-brand">
                <img src="{{ asset('images/fancy/agung-farm-logo.webp') }}" alt="Agung Farm & Garden" width="142" height="74" class="footer-logo">
            </a>
            <p class="footer-description">Agung Farm and Garden by <a href="https://www.instagram.com/sccr.id" target="_blank" rel="noopener noreferrer">@sccr.id</a><br>Healthier choices, grown with care.</p>
            <address class="footer-contact">
                @if ($mapsUrl)
                    <a href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer">{{ $companyProfile->address }} <x-heroicon-o-arrow-up-right aria-hidden="true" /></a>
                @else
                    <span>{{ $companyProfile->address ?: 'Gunungpati, Semarang, Jawa Tengah' }}</span>
                @endif
                @if ($companyProfile->email)
                    <a href="mailto:{{ $companyProfile->email }}">{{ $companyProfile->email }} <x-heroicon-o-arrow-up-right aria-hidden="true" /></a>
                @endif
                @if ($whatsappUrl)
                    <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer"><x-public.whatsapp-icon class="h-5 w-5 shrink-0" />WhatsApp: {{ $companyProfile->whatsapp_number }}</a>
                @endif
            </address>
        </div>

        <nav class="footer-link-group" aria-label="Shop by sector">
            <h3 class="eyebrow">Our sectors</h3>
            @foreach ($footerSectors as $sector)
                <a href="{{ route('catalog', ['sector' => $sector->slug]) }}">{{ $sector->name }}</a>
            @endforeach
            <a href="{{ route('catalog') }}">All products</a>
        </nav>

        <nav class="footer-link-group" aria-label="Customer help">
            <h3 class="eyebrow">Here to help</h3>
            <a href="{{ route('help') }}">How to shop</a>
            <a href="{{ route('help') }}#delivery">Delivery &amp; pickup</a>
            <a href="{{ route('help') }}#payments">Payment methods</a>
        </nav>

        <nav class="footer-link-group" aria-label="About the farm">
            <h3 class="eyebrow">The farm</h3>
            <a href="{{ route('company-profile.show') }}">Our story</a>
            @if ($instagramUrl)
                <a href="{{ $instagramUrl }}" target="_blank" rel="noopener noreferrer">&commat;{{ ltrim($companyProfile->instagram, '@') }} <x-heroicon-o-arrow-up-right aria-hidden="true" /></a>
            @endif
            <a href="{{ route('account') }}">My account</a>
        </nav>
    </div>

    <div class="wrap footer-bottom">
        <span>&copy; {{ now()->year }} Agung Farm &amp; Garden. All rights reserved.</span>
    </div>
</footer>
