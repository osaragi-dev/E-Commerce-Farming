<div x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="if (open && $el.querySelector('.search-dropdown')) $event.stopPropagation(); open = false" class="relative min-w-0 flex-1">
    <form wire:submit="goToResults" class="search-shell" role="search">
        <button type="submit" aria-label="Search products" class="search-submit">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
                <circle cx="11" cy="11" r="7"></circle>
                <path d="m21 21-4.3-4.3"></path>
            </svg>
        </button>
        <input
            type="search"
            wire:model.live.debounce.300ms="query"
            x-on:focus="open = true"
            x-on:input="open = true"
            x-on:keydown.enter="open = false"
            placeholder="Search products"
            aria-label="Search products"
            autocomplete="off"
        >
        <span wire:loading wire:target="query,goToResults" class="sr-only" role="status">Searching products</span>
    </form>

    @if (mb_strlen(trim($query)) >= 2)
        <div x-show="open" x-cloak class="search-dropdown" aria-label="Product suggestions">
            @forelse ($this->suggestions as $product)
                <a href="{{ route('products.show', $product->slug) }}" class="search-dropdown-item" wire:key="search-product-{{ $product->id }}">
                    <div class="search-dropdown-thumb">
                        @php $image = $product->images->first(); @endphp
                        <x-public.stored-image :path="$image?->path" :alt="$product->name" />
                    </div>
                    <span>{{ $product->name }}</span>
                </a>
            @empty
                <p class="search-dropdown-empty" role="status">No products found for "{{ $query }}"</p>
            @endforelse

            <button type="button" wire:click="goToResults" class="search-dropdown-viewall">
                View all results for "{{ $query }}"
            </button>
        </div>
    @endif
</div>
