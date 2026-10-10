@php
    $descriptionParagraphs = $profile->description
        ? preg_split('/\r?\n\r?\n/', trim($profile->description))
        : [];

    $hasContact = $profile->address || $profile->whatsapp_number || $profile->email || $profile->instagram;
@endphp

<div>
    <section class="py-8 md:py-12">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <span class="breadcrumb-current" aria-current="page">About Us</span>
            </nav>
        </div>
    </section>

    <section class="pb-12 md:pb-20">
        <div @class(['wrap', 'grid items-center gap-8 md:grid-cols-[minmax(180px,260px)_minmax(0,1fr)] md:gap-12' => filled($profile->logo)])>
            @if ($profile->logo)
                <div class="flex aspect-square w-full max-w-[220px] items-center justify-center md:max-w-[260px]">
                    <x-public.stored-image :path="$profile->logo" :alt="$profile->company_name.' logo'" loading="eager" class="h-full w-full object-contain" />
                </div>
            @endif

            <div class="min-w-0 max-w-3xl">
                <p class="eyebrow">About Us</p>
                <h1 class="page-heading mt-3">{{ $profile->company_name }}</h1>
                @foreach ($descriptionParagraphs as $paragraph)
                    <p class="page-description mt-5 max-w-2xl">{{ $paragraph }}</p>
                @endforeach
                <a href="{{ route('catalog') }}" class="btn btn-primary mt-7">Explore Our Products</a>
            </div>
        </div>
    </section>

    @if ($profile->galleries->isNotEmpty())
        <section class="bg-[var(--bg-alt)] py-12 md:py-16" aria-labelledby="about-gallery-title">
            <div class="wrap">
                <h2 id="about-gallery-title" class="text-3xl font-normal tracking-tight md:text-4xl">Gallery</h2>
                <div class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                    @foreach ($profile->galleries as $gallery)
                        <button type="button" wire:key="company-gallery-{{ $gallery->id }}" wire:click="openGallery('{{ $gallery->id }}')" class="cp-gallery-item" aria-label="View gallery photo {{ $loop->iteration }}">
                            <span class="cp-gallery-item-media">
                                <x-public.stored-image :path="$gallery->image" :alt="$profile->company_name.' gallery photo '.$loop->iteration" class="h-full w-full object-cover" />
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($selectedGallery)
        <x-public.dialog show="selectedGalleryId" close="closeGallery" id="company-gallery-modal-title" class="cp-gallery-modal">
            <x-slot:title>Gallery photo</x-slot:title>
            <x-public.stored-image :path="$selectedGallery->image" :alt="$profile->company_name.' gallery photo'" class="cp-gallery-modal-image" />
            @if (filled($selectedGallery->description))
                <p class="cp-gallery-modal-description">{{ $selectedGallery->description }}</p>
            @endif
        </x-public.dialog>
    @endif

    @if ($hasContact)
        <section id="contact" class="scroll-mt-24 py-12 md:py-20">
            <div class="wrap">
                <div @class(['cp-contact', 'cp-contact-with-map' => $profile->address])>
                    <div class="cp-contact-panel">
                        <p class="cp-contact-eyebrow">Contact &amp; Location</p>
                        <h2>Good things start<br>with a conversation.</h2>
                        <p class="cp-contact-intro">A question about our produce, a new partnership, or a simple hello. We would love to hear from you.</p>

                        @if ($profile->whatsappUrl() || $profile->email || $profile->instagramUrl())
                            <div class="cp-contact-links">
                                @if ($profile->whatsappUrl())
                                    <a href="{{ $profile->whatsappUrl() }}" target="_blank" rel="noopener noreferrer" class="cp-contact-link">
                                        <span class="cp-contact-icon"><x-public.whatsapp-icon /></span>
                                        <span class="cp-contact-detail"><b>Chat on WhatsApp</b><span>{{ $profile->whatsapp_number }}</span></span>
                                        <x-heroicon-o-arrow-up-right class="cp-contact-arrow" aria-hidden="true" />
                                    </a>
                                @endif
                                @if ($profile->email)
                                    <a href="mailto:{{ $profile->email }}" class="cp-contact-link">
                                        <span class="cp-contact-icon"><x-heroicon-o-envelope aria-hidden="true" /></span>
                                        <span class="cp-contact-detail"><b>Write to us</b><span>{{ $profile->email }}</span></span>
                                        <x-heroicon-o-arrow-up-right class="cp-contact-arrow" aria-hidden="true" />
                                    </a>
                                @endif
                                @if ($profile->instagramUrl())
                                    <a href="{{ $profile->instagramUrl() }}" target="_blank" rel="noopener noreferrer" class="cp-contact-link">
                                        <span class="cp-contact-icon"><x-public.instagram-icon /></span>
                                        <span class="cp-contact-detail"><b>Follow on Instagram</b><span>&commat;{{ ltrim($profile->instagram, '@') }}</span></span>
                                        <x-heroicon-o-arrow-up-right class="cp-contact-arrow" aria-hidden="true" />
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($profile->address)
                        <div class="cp-contact-location">
                            <iframe
                                src="https://www.google.com/maps?q={{ rawurlencode($profile->address) }}&amp;output=embed"
                                title="{{ $profile->company_name }} location on Google Maps"
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                                allowfullscreen
                                class="cp-contact-map"
                            ></iframe>
                            <div class="cp-contact-address">
                                <div>
                                    <p class="eyebrow">Find us here</p>
                                    <p>{{ $profile->address }}</p>
                                </div>
                                <a href="{{ $profile->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="cp-contact-directions">Get directions <x-heroicon-o-arrow-up-right aria-hidden="true" /></a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif
</div>
