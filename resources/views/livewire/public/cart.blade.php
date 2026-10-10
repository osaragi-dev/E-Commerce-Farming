<div>
    <section class="py-8 md:py-14">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">Cart</span>
            </nav>

            <header class="commerce-heading mt-6">
                <p class="eyebrow">Your farm favourites</p>
                <h1>Shopping Cart</h1>
                <p>Select the products you would like to order.</p>
            </header>

            @if (session('cart_message'))
                <p class="text-sm mt-4" role="status">{{ session('cart_message') }}</p>
            @endif

            @if ($this->items->isEmpty())
                <div class="empty-state mt-8">
                    <div class="empty-state-badge"><x-heroicon-o-shopping-cart class="size-8" aria-hidden="true" /></div>
                    <h2>Your cart is empty</h2>
                    <p>Start shopping fresh products from Livestock, Agriculture, and Fisheries.</p>
                    <a href="{{ route('catalog') }}" class="btn btn-primary mt-5">Start shopping</a>
                </div>
            @else
                <div class="commerce-layout mt-8">
                    <div class="commerce-main min-w-0">
                        <div class="cart-table">
                            <div class="cart-table-header">
                                <label class="cart-checkbox-cell">
                                    <input type="checkbox" wire:click="toggleSelectAll" @checked(count($selectedItemIds) === $this->items->count())>
                                    <span class="sr-only">Select all products</span>
                                </label>
                                <span>Product</span>
                                <span class="text-right">Unit price</span>
                                <span class="text-center">Quantity</span>
                                <span class="sr-only">Remove</span>
                            </div>

                            @foreach ($this->items as $item)
                                @php
                                    $variant = $item->productVariant;
                                    $available = $variant->availableForSale();
                                    $itemImage = $variant->product->images->firstWhere('is_primary', true) ?? $variant->product->images->first();
                                @endphp
                                <div wire:key="cart-item-{{ $item->id }}" class="cart-table-row">
                                    <label class="cart-checkbox-cell cart-cell-checkbox">
                                        <input type="checkbox" wire:model.live="selectedItemIds" value="{{ $item->id }}">
                                        <span class="sr-only">Select {{ $variant->product->name }}, {{ $variant->name }}</span>
                                    </label>

                                    <div class="flex items-center gap-3 min-w-0 cart-cell-product">
                                        <a href="{{ route('products.show', $variant->product->slug) }}" class="cart-item-media" aria-label="View {{ $variant->product->name }}">
                                            <x-public.stored-image :path="$itemImage?->path" :alt="$variant->product->name" />
                                        </a>
                                        <div class="min-w-0">
                                            <a href="{{ route('products.show', $variant->product->slug) }}" class="font-medium text-sm">{{ $variant->product->name }}</a>
                                            <label for="cart-variant-{{ $item->id }}" class="sr-only">Variant for {{ $variant->product->name }}</label>
                                            <select
                                                id="cart-variant-{{ $item->id }}"
                                                wire:key="cart-variant-{{ $item->id }}-{{ $variant->id }}"
                                                x-on:change="const variantId = $el.value; $el.value = @js($variant->id); $wire.changeVariant(@js($item->id), variantId)"
                                                wire:loading.attr="disabled"
                                                wire:target="changeVariant"
                                                class="mt-1 block w-full rounded-md border border-[var(--line)] bg-white px-2 py-2 text-xs text-[var(--ink-soft)] focus:border-[var(--forest)] focus:outline-2 focus:outline-[var(--forest)]"
                                                @error('variant.'.$item->id) aria-invalid="true" aria-describedby="cart-variant-error-{{ $item->id }}" @enderror
                                            >
                                                @unless ($variant->product->variants->contains('id', $variant->id))
                                                    <option value="{{ $variant->id }}" selected>{{ $variant->name }} / {{ $variant->unit?->name }} (Unavailable)</option>
                                                @endunless
                                                @foreach ($variant->product->variants as $option)
                                                    <option value="{{ $option->id }}" @selected($option->id === $variant->id)>{{ $option->name }} / {{ $option->unit?->name }}</option>
                                                @endforeach
                                            </select>
                                            @error('variant.'.$item->id)
                                                <p id="cart-variant-error-{{ $item->id }}" class="form-error mt-2" role="alert">{{ $message }}</p>
                                            @enderror
                                            @if ($available < 1)
                                                <span class="badge-sold-out mt-2">Sold out</span>
                                            @elseif ($item->quantity > $available)
                                                <p class="form-error mt-2">Only {{ \App\Support\Quantity::format($available) }} available. Reduce the quantity before checkout.</p>
                                            @elseif ($item->quantity >= $available)
                                                <p class="form-help mt-2">{{ \App\Support\Quantity::format($available) }} left in stock</p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="text-right text-sm cart-cell-price">
                                        <span class="sr-only">Unit price</span>
                                        @if ($variant->has_discount)
                                            <span class="product-price-original">{{ \App\Filament\Support\Rupiah::formatWithPrefix($variant->price) }}</span>
                                        @endif
                                        <span>{{ \App\Filament\Support\Rupiah::formatWithPrefix($variant->effective_price) }} / {{ \App\Support\Quantity::format($variant->price_quantity) }} {{ $variant->unit?->code }}</span>
                                    </div>

                                    <div class="flex justify-center cart-cell-qty">
                                        <x-public.quantity-stepper
                                            :quantity="$item->quantity"
                                            :maximum="$available"
                                            :decrease="'decrementQuantity(\''.$item->id.'\')'"
                                            :increase="'incrementQuantity(\''.$item->id.'\')'"
                                            :label="'Quantity for '.$variant->product->name.', '.$variant->name"
                                            class="cart-qty-stepper"
                                        />
                                    </div>

                                    <div class="text-center cart-cell-action">
                                        <button type="button" wire:click="removeItem('{{ $item->id }}')" wire:confirm="Remove this product from your cart?" wire:loading.attr="disabled" wire:target="removeItem('{{ $item->id }}')" class="cart-remove-icon" aria-label="Remove {{ $variant->product->name }}, {{ $variant->name }}">
                                            <x-heroicon-o-trash class="size-5" aria-hidden="true" />
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <a href="{{ route('catalog') }}" class="text-link mt-6"><x-heroicon-o-arrow-left class="size-4" aria-hidden="true" />Continue shopping</a>
                    </div>

                    <aside class="commerce-summary cart-summary" aria-labelledby="cart-summary-title">
                        <h2 id="cart-summary-title" class="text-xl font-medium">Order summary</h2>
                        <div class="flex justify-between gap-4 text-sm mt-5" style="color: var(--ink-soft)">
                            <span>Subtotal ({{ $this->selectedItems->sum('quantity') }} items selected)</span>
                            <span class="text-right">{{ \App\Filament\Support\Rupiah::formatWithPrefix($this->subtotal) }}</span>
                        </div>
                        <div class="flex justify-between items-baseline gap-4 mt-5 pt-4 border-t" style="border-color: var(--line)">
                            <span class="font-medium">Total</span>
                            <span class="price-box-value">{{ \App\Filament\Support\Rupiah::formatWithPrefix($this->subtotal) }}</span>
                        </div>
                        @if (count($selectedItemIds) > 0)
                            <a href="{{ route('checkout', ['items' => implode(',', $selectedItemIds)]) }}" class="btn btn-primary w-full mt-5">
                                Proceed to checkout ({{ count($selectedItemIds) }})
                                <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
                            </a>
                        @else
                            <button type="button" class="btn btn-primary w-full mt-5" disabled>Select at least 1 product</button>
                        @endif
                        <p class="form-help mt-4">Choose delivery or pickup at checkout.</p>
                    </aside>
                </div>
            @endif
        </div>
    </section>
</div>
