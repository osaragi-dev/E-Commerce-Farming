@props(['product'])

@php
    $image = $product->images->firstWhere('is_primary', true) ?? $product->images->first();
    $activeVariants = $product->variants->where('is_active', true);
    $cheapestVariant = $activeVariants->sortBy('effective_price')->first();
    $isAvailable = $activeVariants->contains(fn ($variant) => $variant->availableForSale() > 0);
    $productUrl = route('products.show', $product->slug);
@endphp

<article {{ $attributes->class(['product-card']) }} data-reveal>
    <p class="product-card-category">{{ $product->category?->name ?? $product->category?->sector?->name }}</p>

    <a href="{{ $productUrl }}" class="product-card-media" aria-label="View {{ $product->name }}">
        @if ($image)
            <img
                src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}"
                alt="{{ $product->name }}"
                loading="lazy"
                decoding="async"
                onerror="this.hidden = true; this.nextElementSibling.hidden = false;"
            >
        @endif
        <span class="product-image-placeholder" @if ($image) hidden @endif>
            <x-heroicon-o-photo class="size-10" aria-hidden="true" />
            <span class="sr-only">Product photo unavailable</span>
        </span>
    </a>

    <div class="product-card-stock">
        <span class="{{ $isAvailable ? 'badge-in-stock' : 'badge-sold-out' }}">{{ $isAvailable ? 'In stock' : 'Sold out' }}</span>
        @if (($product->total_sold ?? 0) > 0)
            <span class="product-sold-count">{{ $product->total_sold }} sold</span>
        @endif
    </div>

    <h3 class="product-card-name"><a href="{{ $productUrl }}">{{ $product->name }}</a></h3>

    <div class="product-price-block">
        @if ($cheapestVariant?->has_discount)
            <span class="product-price-original">{{ \App\Filament\Support\Rupiah::formatWithPrefix($cheapestVariant->price) }}</span>
        @endif
        <span class="product-price-current">
            {{ $cheapestVariant ? \App\Filament\Support\Rupiah::formatWithPrefix($cheapestVariant->effective_price) : 'Price coming soon' }}
            @if ($cheapestVariant) / {{ \App\Support\Quantity::format($cheapestVariant->price_quantity) }} {{ $cheapestVariant->unit?->code }} @endif
            @if ($cheapestVariant)
                <span class="product-price-unit">/ {{ $cheapestVariant->unit?->name }}</span>
            @endif
        </span>
    </div>

    <div class="product-card-actions">
        <button
            type="button"
            wire:click="quickBuyNow('{{ $product->id }}')"
            wire:loading.attr="disabled"
            wire:target="quickBuyNow('{{ $product->id }}'), quickAdd('{{ $product->id }}')"
            class="btn btn-primary w-full"
            @disabled(! $isAvailable)
        >
            <x-heroicon-o-shopping-bag class="size-4 shrink-0" aria-hidden="true" />
            Buy now
        </button>
        <button
            type="button"
            wire:click="quickAdd('{{ $product->id }}')"
            wire:loading.attr="disabled"
            wire:target="quickBuyNow('{{ $product->id }}'), quickAdd('{{ $product->id }}')"
            class="btn btn-ghost w-full"
            @disabled(! $isAvailable)
        >
            <x-heroicon-o-shopping-cart class="size-4 shrink-0" aria-hidden="true" />
            Add to cart
        </button>
    </div>
</article>
