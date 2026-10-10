@props(['label', 'id'])

<div {{ $attributes->class(['product-collection']) }} x-data="publicCarousel">
    <div class="rail-controls">
        <span class="rail-hint">Swipe or scroll to explore</span>
        <button type="button" class="rail-button" x-on:click="previous()" x-bind:disabled="atStart" aria-label="Previous {{ $label }}" aria-controls="{{ $id }}" disabled>
            <x-heroicon-o-arrow-left class="h-5 w-5" aria-hidden="true" />
        </button>
        <button type="button" class="rail-button" x-on:click="next()" x-bind:disabled="atEnd" aria-label="Next {{ $label }}" aria-controls="{{ $id }}">
            <x-heroicon-o-arrow-right class="h-5 w-5" aria-hidden="true" />
        </button>
    </div>
    <div id="{{ $id }}" class="product-rail" x-ref="track" tabindex="0" role="region" aria-label="{{ $label }}">
        {{ $slot }}
    </div>
</div>
