<x-public.dialog show="show" close="close" id="quick-add-title">
    <x-slot:title>{{ $this->product?->name ?? 'Product unavailable' }}</x-slot:title>

    @if ($this->product)
        @php $variant = $this->product->variants->firstWhere('id', $selectedVariantId); @endphp

        <p class="text-sm mb-5" style="color: var(--ink-soft)">{{ $buyNow ? 'Choose your quantity before checkout.' : 'Choose a variant and quantity for your cart.' }}</p>

        @if ($this->product->variants->count() > 1)
            <fieldset class="mb-5">
                <legend class="form-label">Select variant</legend>
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach ($this->product->variants as $option)
                        <button
                            type="button"
                            wire:key="quick-add-variant-{{ $option->id }}"
                            wire:click="selectVariant('{{ $option->id }}')"
                            class="variant-chip {{ $selectedVariantId === $option->id ? 'is-active' : '' }}"
                            aria-pressed="{{ $selectedVariantId === $option->id ? 'true' : 'false' }}"
                        >
                            {{ $option->name }}
                            <span class="variant-chip-unit">{{ $option->unit?->name }}{{ $option->availableForSale() < 1 ? ' · Sold out' : '' }}</span>
                        </button>
                    @endforeach
                </div>
            </fieldset>
        @endif

        @if ($variant)
            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <div>
                    <p class="form-label">Quantity</p>
                    <p class="form-help">{{ $variant->name }} / {{ $variant->unit?->name }}</p>
                </div>
                <x-public.quantity-stepper :quantity="$quantity" :maximum="$variant->availableForSale()" decrease="decrementQuantity" increase="incrementQuantity" />
            </div>

            <p class="stock-summary mb-4">
                <span class="{{ $variant->availableForSale() > 0 ? 'badge-in-stock' : 'badge-sold-out' }}">{{ $variant->availableForSale() > 0 ? 'In stock' : 'Sold out' }}</span>
                <span>Available stock: {{ $variant->availableForSale() }} {{ $variant->unit?->name }}</span>
            </p>

            @error('quantity') <p class="form-error mb-4" role="alert">{{ $message }}</p> @enderror

            <div class="flex justify-between items-baseline gap-4 mb-5 pt-4 border-t" style="border-color: var(--line)">
                <span class="font-medium">Total</span>
                <span class="text-right">
                    @if ($variant->has_discount)
                        <span class="product-price-original">{{ \App\Filament\Support\Rupiah::formatWithPrefix(\App\Support\VariantPricing::subtotal($variant->price, $quantity, $variant->price_quantity)) }}</span>
                    @endif
                    <span class="price-box-value">{{ \App\Filament\Support\Rupiah::formatWithPrefix(\App\Support\VariantPricing::subtotal($variant->effective_price, $quantity, $variant->price_quantity)) }}</span>
                </span>
            </div>
        @else
            <p class="form-help mb-5">Select a variant to see its price and available stock.</p>
        @endif

        <button type="button" wire:click="confirmAdd" wire:loading.attr="disabled" wire:target="confirmAdd" class="btn btn-primary w-full" @disabled(! $variant || $variant->availableForSale() < 1)>
            @if (! $buyNow)<x-heroicon-o-shopping-cart class="size-4" aria-hidden="true" />@endif
            <span wire:loading.remove wire:target="confirmAdd">{{ $buyNow ? 'Continue to checkout' : 'Add to cart' }}</span>
            <span wire:loading wire:target="confirmAdd">{{ $buyNow ? 'Opening checkout...' : 'Adding to cart...' }}</span>
        </button>
    @else
        <p class="form-help">This product is no longer available. Please choose another product.</p>
    @endif
</x-public.dialog>
