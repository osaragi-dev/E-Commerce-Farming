<x-filament-panels::page>
    <div x-data="posCart()" x-on:pos-cart-cleared.window="clear()" x-on:pos-prices-updated.window="updatePrices($event.detail.prices, $event.detail.priceQuantities)" x-on:modal-closed.window="if ($event.detail.id === 'pos-variants') choosing = false" x-effect="$dispatch(choosing ? 'open-modal' : 'close-modal', { id: 'pos-variants' })">
    @if ($lastOrderNumber)
        <div role="status" class="rounded-xl bg-primary-50 px-5 py-4 text-sm text-primary-800 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:text-primary-200 dark:ring-primary-500/20">
                <span class="font-semibold">{{ $lastOrderNumber }}</span> &middot; Payment: {{ $lastPaymentStatus }} &middot; Order: Ready to Process
        </div>
    @endif
    <div class="grid items-start gap-6 lg:grid-cols-3">
        <section aria-label="Product catalog" class="min-w-0 space-y-5 lg:col-span-2">
            <div class="flex flex-col gap-3 sm:flex-row">
                <div class="min-w-0 flex-1">
                    <label for="pos-search" class="sr-only">Search products</label>
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                        <x-filament::input id="pos-search" type="search" placeholder="Search products…" wire:model.live.debounce.300ms="search" />
                    </x-filament::input.wrapper>
                </div>
                <div class="sm:w-52">
                    <label for="pos-category" class="sr-only">Category</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="pos-category" wire:model.live="category">
                            <option value="">All categories</option>
                            @foreach ($this->categories as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm pos-muted">
                <span>{{ $this->products->total() }} products</span>
                <span>Choose a product to add it to your cart</span>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($this->products as $product)
                    @php
                        $inStock = $product->variants->contains(fn ($variant) => $variant->availableForSale() > 0);
                        $minimumPrice = $product->variants->min('effective_price');
                        $photo = $product->generalImages->first();
                        $clientVariants = $product->variants->map(fn ($variant) => [
                            'id' => $variant->id,
                            'name' => $product->name,
                            'variant' => $variant->name,
                            'price' => $variant->effective_price,
                            'price_quantity' => $variant->price_quantity,
                            'unit' => $variant->unit?->code,
                            'stock' => \App\Support\Quantity::format($variant->availableForSale()),
                        ])->values()->all();
                    @endphp
                    <article wire:key="product-{{ $product->id }}" class="flex flex-col overflow-hidden rounded-xl pos-surface shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
                        <div x-data="{ imageFailed: false }" class="relative aspect-[4/3] overflow-hidden bg-gray-100 dark:bg-gray-800">
                            @if ($photo)
                                <img x-show="! imageFailed" x-on:error="imageFailed = true" src="{{ Storage::disk('public')->url($photo->path) }}" alt="{{ $product->name }}" loading="lazy" class="h-full w-full object-cover" />
                            @endif
                            <div @if ($photo) x-show="imageFailed" x-cloak @endif class="flex h-full items-center justify-center text-gray-300 dark:text-gray-600">
                                <x-filament::icon icon="heroicon-o-photo" class="size-12" />
                            </div>
                            @unless ($inStock)
                                <span class="absolute top-3 left-3 rounded-lg bg-white/95 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-900/95 dark:text-gray-300">Out of stock</span>
                            @endunless
                        </div>
                        <div class="flex flex-1 flex-col gap-3 p-4">
                            <div class="flex-1 space-y-1">
                                <h2 class="font-semibold text-gray-950 dark:text-white">{{ $product->name }}</h2>
                                <p class="text-xs pos-muted">
                                    @if ($product->variants->count() === 1)
                                        {{ $product->variants->first()->name }} &middot; Stock: {{ \App\Support\Quantity::format($product->variants->first()->availableForSale()) }}
                                    @else
                                        {{ $product->variants->count() }} variants
                                    @endif
                                </p>
                            </div>
                            <p class="font-semibold text-primary-600 dark:text-primary-400">
                                @if ($product->variants->count() > 1)<span class="text-xs font-normal">From</span>@endif
                                {{ \App\Filament\Support\Rupiah::formatWithPrefix($minimumPrice) ?? 'Unavailable' }}
                                @if ($product->variants->count() === 1)
                                    / {{ \App\Support\Quantity::format($product->variants->first()->price_quantity) }} {{ $product->variants->first()->unit?->code }}
                                @endif
                            </p>
                            <x-filament::button color="success" icon="heroicon-o-plus" class="w-full" :disabled="! $inStock" x-on:click="choose({{ \Illuminate\Support\Js::from($clientVariants) }})" x-bind:disabled="busy || {{ $inStock ? 'false' : 'true' }}">
                                Add to cart
                            </x-filament::button>
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 px-6 py-16 text-center sm:col-span-2 xl:col-span-3 dark:border-gray-700">
                        <h2 class="font-semibold text-gray-950 dark:text-white">No products found</h2>
                        <p class="mt-2 text-sm pos-muted">Try another name or category.</p>
                    </div>
                @endforelse
            </div>
            {{ $this->products->links() }}
        </section>
        <section aria-label="Shopping cart" class="overflow-hidden rounded-xl pos-surface shadow-sm ring-1 ring-gray-950/5 lg:sticky lg:top-24 dark:ring-white/10">
            <div class="flex items-center justify-between border-b pos-divider p-5">
                <h2 class="flex items-center gap-2 text-lg font-semibold text-gray-950 dark:text-white">
                    <x-filament::icon icon="heroicon-o-shopping-cart" class="size-5" />
                    Cart <span class="text-sm font-normal pos-muted"><span x-text="count"></span> items</span>
                </h2>
                <x-filament::link tag="button" color="gray" x-show="lines.length" x-cloak x-bind:disabled="busy" x-on:click="if (confirm('Clear all items from the cart?')) clear()">Clear</x-filament::link>
            </div>
            <div class="space-y-2 border-b pos-divider p-5">
                <x-filament::button color="gray" class="w-full" icon="heroicon-o-user" wire:click="mountAction('customer')" x-bind:disabled="busy">
                    {{ $this->selectedCustomer?->name ?? 'Customer' }}
                </x-filament::button>
                @error('customer')<p role="alert" class="text-sm pos-error">{{ $message }}</p>@enderror
            </div>
            <div class="max-h-[55vh] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                <template x-for="line in lines" :key="line.id">
                    <div class="space-y-3 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-gray-950 dark:text-white"><span x-text="line.name"></span></h3>
                                <p class="text-xs pos-muted"><span x-text="line.variant"></span> &middot; <span x-text="priceLabel(line)"></span></p>
                            </div>
                            <x-filament::icon-button icon="heroicon-o-x-mark" color="gray" label="Remove item" x-bind:aria-label="'Remove ' + line.name" x-bind:disabled="busy" x-on:click="remove(line.id)" />
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-1">
                                <x-filament::icon-button icon="heroicon-o-minus" color="gray" label="Decrease quantity" x-bind:disabled="busy || line.quantity <= 1" x-on:click="quantity(line.id, Number((line.quantity - 1).toFixed(3)))" />
                                <div class="w-16">
                                    <x-filament::input.wrapper>
                                        <x-filament::input type="text" inputmode="decimal" x-bind:value="line.quantity" x-bind:disabled="busy" x-bind:aria-label="'Quantity for ' + line.name" x-on:change="quantity(line.id, $event.target.value); $event.target.value = line.quantity" />
                                    </x-filament::input.wrapper>
                                </div>
                                <x-filament::icon-button icon="heroicon-o-plus" color="gray" label="Increase quantity" x-bind:disabled="busy || line.quantity >= line.stock" x-on:click="quantity(line.id, Number((line.quantity + 1).toFixed(3)))" />
                            </div>
                            <span class="text-sm font-semibold text-gray-950 dark:text-white"><span x-text="money(lineCents(line) / 100)"></span></span>
                        </div>

                    </div>
                </template>
                    <div x-show="!lines.length" class="flex flex-col items-center gap-3 px-5 py-16 text-center">
                        <x-filament::icon icon="heroicon-o-shopping-bag" class="size-10 text-gray-300 dark:text-gray-600" />
                        <h3 class="font-medium text-gray-950 dark:text-white">Your cart is empty</h3>
                        <p class="text-sm pos-muted">Add products to start an order.</p>
                    </div>
            </div>
            <div class="space-y-4 border-t pos-divider p-5">
                <p x-show="error" x-text="error" role="alert" class="text-sm pos-error"></p>
                @error('items')<p role="alert" class="text-sm pos-error">{{ $message }}</p>@enderror
                @error('system_freeze')<p role="alert" class="text-sm pos-error">{{ $message }}</p>@enderror
                <div class="flex items-center justify-between gap-3 font-semibold text-gray-950 dark:text-white">
                    <span>Subtotal</span>
                    <span><span x-text="money(subtotal)"></span></span>
                </div>
                <p class="text-xs pos-muted">Shipping and payment are selected at checkout.</p>
                <x-filament::button class="w-full" icon="heroicon-o-credit-card" x-bind:disabled="busy || !lines.length" x-on:click="checkout($wire)">
                    <span x-text="busy ? 'Opening checkout...' : 'Checkout'">Checkout</span>
                </x-filament::button>
            </div>
        </section>
    </div>
    <x-filament::modal id="pos-variants">
        <x-slot name="heading">Choose a variant</x-slot>
        <div class="space-y-3">
            <template x-for="variant in variants" :key="variant.id">
                <x-filament::button color="gray" class="w-full" x-bind:disabled="variant.stock <= 0" x-on:click="add(variant)">
                    <span x-text="variant.variant + ' &middot; ' + priceLabel(variant) + ' &middot; Stock: ' + variant.stock"></span>
                </x-filament::button>
            </template>
            <p x-show="error" x-text="error" role="alert" class="text-sm pos-error"></p>
        </div>
    </x-filament::modal>
    </div>
</x-filament-panels::page>
