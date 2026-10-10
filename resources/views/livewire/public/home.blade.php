<div>
    <section class="farm-hero" x-data="farmHero" aria-labelledby="hero-title" wire:ignore>
        <video class="farm-hero-media" x-ref="video" data-src="{{ asset('images/fancy/farm-hero.mp4') }}" poster="{{ asset('images/fancy/farm-scene-0.webp') }}" muted playsinline loop preload="none" aria-hidden="true"></video>
        <div class="farm-hero-shade"></div>
        <div class="wrap farm-hero-content">
            <p class="farm-hero-intro">From our farm in Semarang</p>
            <h1 id="hero-title" class="farm-hero-title">Good things.<br>Grown close.</h1>
            <p class="farm-hero-description">Eggs, greens and freshwater fish.<br>A little closer to the source.</p>
            <div class="farm-hero-actions">
                <a href="#products" class="btn btn-primary">Shop best sellers <x-heroicon-o-arrow-up-right class="h-4 w-4" aria-hidden="true" /></a>
                <a href="{{ route('company-profile.show') }}" class="btn btn-ghost">Meet the farm</a>
            </div>
        </div>
        <div class="farm-hero-signature">
            <p>Agung Farm &amp; Garden</p>
            <div class="farm-hero-credit">
                <span>by</span>
                <span class="farm-hero-credit-logo"><img src="{{ asset('images/fancy/sccr-logo.png') }}" alt="SCCR" width="465" height="307"></span>
            </div>
        </div>
        <button type="button" class="farm-hero-toggle" x-on:click="toggle()" x-bind:aria-pressed="playing" x-show="! unavailable" x-cloak>
            <x-heroicon-o-pause class="h-4 w-4" x-show="playing" aria-hidden="true" />
            <x-heroicon-o-play class="h-4 w-4" x-show="! playing" aria-hidden="true" />
            <span x-text="playing ? 'Pause video' : 'Play video'">Play video</span>
        </button>
    </section>

    @if ($newestProducts->isNotEmpty())
        <section class="wrap farm-section" aria-labelledby="new-products-title" x-data="publicReveal">
            <div class="farm-section-heading" data-reveal>
                <p class="eyebrow">New products</p>
                <h2 id="new-products-title">New at the farm.</h2>
                <p class="farm-section-description">Explore the latest additions to our catalog.</p>
            </div>
            <x-public.product-rail id="new-products-rail" label="new products" wire:key="new-products-collection">
                @foreach ($newestProducts as $product)
                    <x-public.product-card :product="$product" wire:key="new-product-{{ $product->id }}" />
                @endforeach
            </x-public.product-rail>
        </section>
    @endif

    <section id="products" class="wrap farm-section" aria-labelledby="products-title" x-data="publicReveal">
        <div class="farm-section-heading" data-reveal>
            <p class="eyebrow">Best Sellers</p>
            <h2 id="products-title">Fresh from our farm.</h2>
            <p class="farm-section-description">The products our customers come back for.</p>
        </div>
        <div class="farm-product-filters">
            <div class="farm-sector-filters" role="group" aria-label="Filter best sellers by sector">
                <button type="button" class="pill-filter {{ $productSector === '' ? 'is-active' : '' }}" wire:click="$set('productSector', '')" aria-pressed="{{ $productSector === '' ? 'true' : 'false' }}">All sectors</button>
                @foreach ($sectors as $sector)
                    <button type="button" class="pill-filter {{ $productSector === $sector->slug ? 'is-active' : '' }}" wire:click="$set('productSector', @js($sector->slug))" aria-pressed="{{ $productSector === $sector->slug ? 'true' : 'false' }}" wire:key="sector-filter-{{ $sector->id }}">{{ $sector->name }}</button>
                @endforeach
            </div>
            <label class="search-shell" for="best-seller-search">
                <x-heroicon-o-magnifying-glass class="h-5 w-5 shrink-0" aria-hidden="true" />
                <span class="sr-only">Search best sellers</span>
                <input id="best-seller-search" type="search" wire:model.live.debounce.300ms="productSearch" placeholder="Find something fresh..." autocomplete="off">
            </label>
        </div>
        <div wire:loading.delay wire:target="productSector,productSearch,resetProductFilters" role="status" class="farm-section-description">Updating products...</div>
        @if ($bestSellerProducts->isNotEmpty())
            <x-public.product-rail id="best-sellers-rail" label="best sellers" wire:key="best-seller-collection-{{ $productSector }}-{{ md5($productSearch) }}">
                @foreach ($bestSellerProducts as $product)
                    <x-public.product-card :product="$product" wire:key="best-seller-{{ $product->id }}" />
                @endforeach
            </x-public.product-rail>
        @else
            <div class="farm-empty-state" role="status">
                <h3>{{ $hasBestSellers ? 'No products found' : 'Fresh favourites are on their way.' }}</h3>
                <p>{{ $hasBestSellers ? 'Try another name or explore all sectors.' : 'Discover what is available in our full catalog.' }}</p>
                @if ($productSector !== '' || $productSearch !== '')
                    <button type="button" class="btn btn-ghost" wire:click="resetProductFilters">Show all best sellers</button>
                @endif
            </div>
        @endif
        <a href="{{ route('catalog') }}" class="farm-text-link">Explore all products <x-heroicon-o-arrow-up-right class="h-4 w-4" aria-hidden="true" /></a>
    </section>

    <section id="sectors" class="farm-sectors" aria-labelledby="sectors-title" x-data="publicReveal">
        <div class="wrap">
            <div class="farm-section-heading" data-reveal>
                <p class="eyebrow">Explore our sectors</p>
                <h2 id="sectors-title">One farm.<br>Plenty to discover.</h2>
                <p class="farm-section-description">Find your favourites by sector.</p>
            </div>
            <div class="farm-sector-grid">
                @forelse ($sectors as $sector)
                    <a href="{{ route('catalog', ['sector' => $sector->slug]) }}" id="sector-{{ $sector->slug }}" class="farm-sector" wire:key="sector-{{ $sector->id }}" data-reveal>
                        <div class="farm-sector-media">
                            <x-public.stored-image :path="$sector->image" :alt="$sector->name" />
                        </div>
                        <div class="farm-sector-title"><h3>{{ $sector->name }}</h3><x-heroicon-o-arrow-up-right class="h-6 w-6" aria-hidden="true" /></div>
                        <p>{{ $sector->categories->take(2)->pluck('name')->join(' & ') }}</p>
                        <span class="farm-text-link">Shop {{ $sector->name }} <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" /></span>
                    </a>
                @empty
                    <p>No active sectors yet. Browse our catalog for updates.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section id="farm" class="wrap farm-section" aria-label="Instagram Reels">
        <x-public.instagram-reels />
        @if ($profile->instagramUrl())
            <a href="{{ $profile->instagramUrl() }}" class="farm-text-link" target="_blank" rel="noopener noreferrer">Follow life at the farm <x-heroicon-o-arrow-up-right class="h-4 w-4" aria-hidden="true" /></a>
        @endif
    </section>

    <section class="farm-ordering" aria-labelledby="order-title" x-data="publicReveal">
        <div class="wrap">
            <div class="farm-section-heading"><p class="eyebrow">How to order</p><h2 id="order-title">From browsing<br>to your basket.</h2></div>
            <div class="farm-order-steps" data-reveal>
                <article><span>01</span><h3>Choose your favourites</h3><p>Browse products and compare the available units and prices.</p></article>
                <article><span>02</span><h3>Make it yours</h3><p>Sign in, choose a variant and quantity, then add to cart or buy now.</p></article>
                <article><span>03</span><h3>Choose how it arrives</h3><p>Select delivery or pickup at checkout. Review your order and follow the payment instructions.</p></article>
            </div>
            <div class="farm-faq">
                <details><summary>Can I collect my order at the farm?<x-heroicon-o-plus class="h-5 w-5 shrink-0" aria-hidden="true" /></summary><p>Yes. Select pickup at checkout and review the collection location before confirming your order.</p></details>
                <details><summary>How do delivery and payment work?<x-heroicon-o-plus class="h-5 w-5 shrink-0" aria-hidden="true" /></summary><p>Choose your delivery location and review the final total at checkout. Cash and QRIS options are shown there. For QRIS, you can upload proof during checkout or from your order page.</p></details>
            </div>
            <a href="{{ route('help') }}" class="farm-text-link">Visit our help center <x-heroicon-o-arrow-up-right class="h-4 w-4" aria-hidden="true" /></a>
        </div>
    </section>

    <section class="farm-cta" aria-labelledby="cta-title" x-data="publicReveal">
        <div class="wrap farm-cta-layout">
            <div><h2 id="cta-title">Good food starts here.</h2><p>Questions? Talk to our farm team.</p></div>
            <div class="farm-cta-actions">
                <a href="{{ route('catalog') }}" class="btn btn-ghost"><x-heroicon-o-shopping-bag class="h-5 w-5" aria-hidden="true" />Shop farm products</a>
                @if ($profile->whatsappUrl())
                    <a href="{{ $profile->whatsappUrl() }}" class="farm-text-link" target="_blank" rel="noopener noreferrer"><x-public.whatsapp-icon class="h-5 w-5" />Chat on WhatsApp</a>
                @endif
            </div>
        </div>
    </section>
</div>
