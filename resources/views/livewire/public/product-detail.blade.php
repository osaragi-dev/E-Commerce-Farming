<div class="product-detail-page">
    <section class="product-detail-section">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('catalog', ['sector' => $product->category?->sector?->slug]) }}">{{ $product->category?->sector?->name }}</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('catalog', ['sector' => $product->category?->sector?->slug, 'category' => [$product->category?->slug]]) }}">{{ $product->category?->name }}</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">{{ $product->name }}</span>
            </nav>

            <div class="product-detail-layout">
                <div wire:key="gallery-{{ $this->selectedVariant?->id ?? 'none' }}" x-data="{ active: 0, failed: {}, images: {{ \Illuminate\Support\Js::from($this->imageUrls) }} }" class="product-detail-gallery">
                    <div class="product-detail-media" role="region" aria-label="{{ $product->name }} photos">
                        <template x-if="images.length > 0">
                            <img :src="images[active]" x-show="!failed[active]" x-on:error="failed[active] = true" alt="{{ $product->name }}" decoding="async">
                        </template>
                        <template x-if="images.length === 0 || failed[active]">
                            <div class="product-detail-media-empty">
                                <x-heroicon-o-photo class="size-12" aria-hidden="true" />
                                <span class="text-sm mt-3">Product photo unavailable</span>
                            </div>
                        </template>

                        <template x-if="images.length > 1">
                            <button type="button" x-on:click="active = (active - 1 + images.length) % images.length" class="gallery-arrow gallery-arrow-left" aria-label="Previous photo"><x-heroicon-o-chevron-left class="size-5" aria-hidden="true" /></button>
                        </template>
                        <template x-if="images.length > 1">
                            <button type="button" x-on:click="active = (active + 1) % images.length" class="gallery-arrow gallery-arrow-right" aria-label="Next photo"><x-heroicon-o-chevron-right class="size-5" aria-hidden="true" /></button>
                        </template>
                        <span class="sr-only" x-show="images.length > 0" x-text="'Photo ' + (active + 1) + ' of ' + images.length" aria-live="polite"></span>
                    </div>

                    <template x-if="images.length > 1">
                        <div class="flex gap-2 mt-3 overflow-x-auto pb-2" role="group" aria-label="Choose product photo">
                            <template x-for="(url, index) in images" :key="url">
                                <button type="button" x-on:click="active = index" class="product-thumb shrink-0" :class="{ 'is-active': active === index }" :aria-pressed="active === index" :aria-label="'View photo ' + (index + 1)">
                                    <img :src="url" alt="" loading="lazy" x-on:error="$el.hidden = true">
                                </button>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="product-detail-summary">
                    <p class="eyebrow">{{ $product->category?->sector?->name }} / {{ $product->category?->name }}</p>
                    <h1 class="product-detail-title">{{ $product->name }}</h1>

                    <div class="stock-summary mt-4">
                        <span class="{{ $this->selectedVariant ? 'badge-in-stock' : 'badge-sold-out' }}">{{ $this->selectedVariant ? 'In stock' : 'Sold out' }}</span>
                    </div>

                    <div class="price-box mt-5">
                        @php $range = $this->priceRange; @endphp
                        @if ($range['min'] === null)
                            <span class="text-lg" style="color: var(--ink-soft)">Price coming soon</span>
                        @elseif ($range['min'] == $range['max'])
                            @if ($range['has_discount'] && $range['original_min'] == $range['original_max'])
                                <span class="product-price-original">{{ \App\Filament\Support\Rupiah::formatWithPrefix($range['original_min']) }}</span>
                            @endif
                            <span class="price-box-value">{{ \App\Filament\Support\Rupiah::formatWithPrefix($range['min']) }}</span>
                            @if ($product->variants->count() === 1) / {{ \App\Support\Quantity::format($product->variants->first()->price_quantity) }} {{ $product->variants->first()->unit?->code }} @endif
                        @else
                            @if ($range['has_discount'])
                                <span class="product-price-original">{{ \App\Filament\Support\Rupiah::formatWithPrefix($range['original_min']) }} &ndash; {{ \App\Filament\Support\Rupiah::formatWithPrefix($range['original_max']) }}</span>
                            @endif
                            <span class="price-box-value">{{ \App\Filament\Support\Rupiah::formatWithPrefix($range['min']) }} &ndash; {{ \App\Filament\Support\Rupiah::formatWithPrefix($range['max']) }}</span>
                        @endif
                    </div>

                    @if ($this->sortedVariants->isNotEmpty())
                        <fieldset class="mt-7">
                            <legend class="form-label">Select variant</legend>
                            <div class="flex flex-wrap gap-2 mt-3">
                                @foreach ($this->sortedVariants as $variant)
                                    @php $variantThumb = $variant->images->first() ?? $product->images->firstWhere('is_primary', true) ?? $product->images->first(); @endphp
                                    <button
                                        type="button"
                                        wire:key="variant-chip-{{ $variant->id }}"
                                        wire:click="selectVariant('{{ $variant->id }}')"
                                        class="variant-chip has-thumb {{ $this->selectedVariant?->id === $variant->id ? 'is-active' : '' }}"
                                        aria-pressed="{{ $this->selectedVariant?->id === $variant->id ? 'true' : 'false' }}"
                                    >
                                        @if ($variantThumb)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($variantThumb->path) }}" alt="" class="variant-chip-thumb" loading="lazy" onerror="this.hidden = true">
                                        @endif
                                        <span>{{ $variant->name }}<span class="variant-chip-unit">{{ $variant->unit?->name }}</span></span>
                                    </button>
                                @endforeach
                            </div>
                        </fieldset>

                        @if ($this->selectedVariant)
                            <div class="mt-7">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <div>
                                        <p class="form-label">Quantity</p>
                                    </div>
                                    <x-public.quantity-stepper :quantity="$quantity" :maximum="$this->selectedVariant->availableForSale()" decrease="decrementQuantity" increase="incrementQuantity" />
                                </div>

                                @error('quantity') <p class="form-error mt-3" role="alert">{{ $message }}</p> @enderror

                                <div class="purchase-actions mt-6">
                                    <button type="button" wire:click="buyNow" wire:loading.attr="disabled" wire:target="buyNow,openAddToCart" class="btn btn-primary flex-1" @disabled($this->selectedVariant->availableForSale() < 1)>Buy now</button>
                                    <button type="button" wire:click="openAddToCart" wire:loading.attr="disabled" wire:target="buyNow,openAddToCart" class="btn btn-ghost flex-1" @disabled($this->selectedVariant->availableForSale() < 1)><x-heroicon-o-shopping-cart class="size-4" aria-hidden="true" />Add to cart</button>
                                </div>

                            </div>
                        @endif
                    @else
                        <p class="mt-6 text-sm" style="color: var(--ink-soft)">{{ $product->variants->isNotEmpty() ? 'This product is currently sold out.' : 'No variants available for this product yet.' }}</p>
                        <div class="purchase-actions mt-5">
                            <button type="button" class="btn btn-primary flex-1" disabled>Buy now</button>
                            <button type="button" class="btn btn-ghost flex-1" disabled><x-heroicon-o-shopping-cart class="size-4" aria-hidden="true" />Add to cart</button>
                        </div>
                    @endif
                    @if ($product->description)
                        <section class="product-description" aria-labelledby="product-description-title">
                            <h2 id="product-description-title">About this product</h2>
                            <p>{{ $product->description }}</p>
                        </section>
                    @endif
                </div>
            </div>

            @if ($this->recommendedProducts->isNotEmpty())
                <section class="product-recommendations" aria-labelledby="recommended-title">
                    <div class="flex flex-wrap justify-between items-end gap-4 mb-6">
                        <div>
                            <p class="eyebrow">More from the farm</p>
                            <h2 id="recommended-title" class="text-2xl md:text-3xl font-medium mt-2">You May Also Like</h2>
                        </div>
                        <a href="{{ route('catalog') }}" class="text-link">Explore all products <x-heroicon-o-arrow-up-right class="size-4" aria-hidden="true" /></a>
                    </div>
                    <div class="product-grid" x-data="publicReveal">
                        @foreach ($this->recommendedProducts as $related)
                            <x-public.product-card :product="$related" wire:key="recommended-product-{{ $related->id }}" />
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </section>

    @if ($this->selectedVariant)
        <x-public.dialog show="showBuyNowConfirm" close="cancelBuyNowConfirm" id="buy-now-title">
            <x-slot:title>Confirm purchase</x-slot:title>
            <p class="text-sm mb-5" style="color: var(--ink-soft)">Review your selection before checkout.</p>

            <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-3 text-sm">
                <dt style="color: var(--ink-soft)">Product</dt>
                <dd class="text-right break-words">{{ $product->name }}</dd>
                <dt style="color: var(--ink-soft)">Variant</dt>
                <dd class="text-right">{{ $this->selectedVariant->name }}</dd>
                <dt>Price</dt>
                <dd>{{ \App\Filament\Support\Rupiah::formatWithPrefix($this->selectedVariant->effective_price) }} / {{ \App\Support\Quantity::format($this->selectedVariant->price_quantity) }} {{ $this->selectedVariant->unit?->code }}</dd>
            </dl>

            <div class="flex justify-between items-center gap-4 mt-5">
                <span class="text-sm">Quantity</span>
                <x-public.quantity-stepper :quantity="$quantity" :maximum="$this->selectedVariant->availableForSale()" decrease="decrementQuantity" increase="incrementQuantity" />
            </div>

            <div class="flex justify-between items-baseline gap-4 my-5 pt-4 border-t" style="border-color: var(--line)">
                <span class="font-medium">Total</span>
                <span class="price-box-value">{{ \App\Filament\Support\Rupiah::formatWithPrefix(\App\Support\VariantPricing::subtotal($this->selectedVariant->effective_price, $quantity, $this->selectedVariant->price_quantity)) }}</span>
            </div>

            <div class="purchase-actions">
                <button type="button" wire:click="cancelBuyNowConfirm" class="btn btn-ghost flex-1">Cancel</button>
                <button type="button" wire:click="confirmBuyNow" wire:loading.attr="disabled" wire:target="confirmBuyNow" class="btn btn-primary flex-1">
                    <span wire:loading.remove wire:target="confirmBuyNow">Continue to checkout</span>
                    <span wire:loading wire:target="confirmBuyNow">Opening checkout...</span>
                </button>
            </div>
        </x-public.dialog>
    @endif
</div>
