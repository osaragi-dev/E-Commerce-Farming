<div>
    <section class="py-8 md:py-14">
        <div class="wrap max-w-[640px]">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('cart') }}">Cart</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">Checkout</span>
            </nav>

            <header class="commerce-heading mt-6">
                <p class="eyebrow">Fresh from the farm</p>
                <h1>Checkout</h1>
                <p>Confirm your details and choose how to receive your order.</p>
            </header>

            @if ($this->cartItems->isEmpty())
                <div class="empty-state mt-8">
                    <div class="empty-state-badge"><x-heroicon-o-shopping-bag class="size-8" aria-hidden="true" /></div>
                    <h2>No products selected</h2>
                    <p>Go back to your cart and select the products you want to check out.</p>
                    <a href="{{ route('cart') }}" class="btn btn-primary mt-5">Back to cart</a>
                </div>
            @else
                <form wire:submit="placeOrder" class="mt-8">
                    <div class="space-y-5">
                        <section class="commerce-panel" aria-labelledby="recipient-title">
                            <h2 id="recipient-title" class="text-xl font-medium">Recipient Info</h2>
                            <div>
                                <div class="form-field">
                                    <label for="recipient-name" class="form-label">Recipient Name <span class="text-required" aria-hidden="true">*</span></label>
                                    <input id="recipient-name" type="text" wire:model="recipientName" class="form-input" autocomplete="name" maxlength="255" required aria-invalid="{{ $errors->has('recipientName') ? 'true' : 'false' }}" @error('recipientName') aria-describedby="recipient-name-error" @enderror>
                                    @error('recipientName') <p id="recipient-name-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                                </div>
                                <div class="form-field">
                                    <label for="recipient-phone" class="form-label">Recipient Phone Number <span class="text-required" aria-hidden="true">*</span></label>
                                    <input id="recipient-phone" type="tel" wire:model="recipientPhone" class="form-input" autocomplete="tel" maxlength="30" required aria-invalid="{{ $errors->has('recipientPhone') ? 'true' : 'false' }}" @error('recipientPhone') aria-describedby="recipient-phone-error" @enderror>
                                    @error('recipientPhone') <p id="recipient-phone-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <fieldset class="form-field">
                                <legend class="form-label">Shipping Method <span class="text-required" aria-hidden="true">*</span></legend>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                    @foreach (\App\Enums\ShippingMethod::cases() as $method)
                                        <label class="field-choice">
                                            <input type="radio" wire:model.live="shippingMethod" value="{{ $method->value }}" name="shipping-method">
                                            @if ($method === \App\Enums\ShippingMethod::Pickup)
                                                <x-heroicon-o-building-storefront class="size-5 shrink-0" aria-hidden="true" />
                                            @else
                                                <x-heroicon-o-truck class="size-5 shrink-0" aria-hidden="true" />
                                            @endif
                                            <span>{{ $method->getLabel() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('shippingMethod') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            </fieldset>

                            <div class="form-field">
                                <label for="shipping-address" class="form-label">Shipping Address <span class="text-required" aria-hidden="true">*</span></label>
                                <select id="shipping-address" wire:model.live="shippingAddress" class="form-input" required aria-invalid="{{ $errors->has('shippingAddress') ? 'true' : 'false' }}" @error('shippingAddress') aria-describedby="shipping-address-error" @enderror>
                                    @if ($shippingMethod !== \App\Enums\ShippingMethod::Pickup->value)
                                        <option value="">Select a delivery location</option>
                                    @endif
                                    @if ($shippingMethod === \App\Enums\ShippingMethod::Pickup->value)
                                        <option value="{{ \App\Livewire\Public\Checkout::PICKUP_LOCATION }}">{{ \App\Livewire\Public\Checkout::PICKUP_LOCATION }}</option>
                                    @else
                                        @foreach (\App\Livewire\Public\Checkout::DELIVERY_LOCATIONS as $location)
                                            <option value="{{ $location }}">{{ $location }}</option>
                                        @endforeach
                                        <option value="{{ \App\Livewire\Public\Checkout::OTHER_LOCATION }}">{{ \App\Livewire\Public\Checkout::OTHER_LOCATION }}</option>
                                    @endif
                                </select>
                                @if ($shippingMethod === \App\Enums\ShippingMethod::Pickup->value && $shippingAddress === \App\Livewire\Public\Checkout::PICKUP_LOCATION)
                                    <p class="form-help">Pickup location: Agung Farm &amp; Garden.</p>
                                    <a
                                        href="{{ \App\Livewire\Public\Checkout::pickupLocationMapsUrl() }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="form-help checkout-pickup-map-link mt-2 inline-flex items-start gap-2 underline"
                                        aria-label="View {{ \App\Livewire\Public\Checkout::PICKUP_LOCATION }} on Google Maps"
                                    >
                                        <x-heroicon-o-map-pin class="size-4 shrink-0" aria-hidden="true" />
                                        {{ \App\Livewire\Public\Checkout::PICKUP_LOCATION_MAPS_ADDRESS }}
                                    </a>
                                @endif
                                @error('shippingAddress') <p id="shipping-address-error" class="form-error" role="alert">{{ $message }}</p> @enderror

                                @if ($shippingAddress === \App\Livewire\Public\Checkout::OTHER_LOCATION)
                                    <div class="form-field">
                                        <label for="shipping-address-other" class="form-label">Other Shipping Address <span class="text-required" aria-hidden="true">*</span></label>
                                        <textarea id="shipping-address-other" wire:model="shippingAddressOther" rows="3" class="form-input" placeholder="Enter your full delivery address" autocomplete="street-address" maxlength="1000" required aria-invalid="{{ $errors->has('shippingAddressOther') ? 'true' : 'false' }}" @error('shippingAddressOther') aria-describedby="shipping-address-other-error" @enderror></textarea>
                                        @error('shippingAddressOther') <p id="shipping-address-other-error" class="form-error" role="alert">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                            </div>
                        </section>

                        <section class="commerce-panel" aria-labelledby="payment-title">
                            <h2 id="payment-title" class="text-xl font-medium">Payment Method</h2>
                            <fieldset class="mt-4">
                                <legend class="sr-only">Choose a payment method</legend>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <label class="field-choice">
                                        <input type="radio" wire:model.live="paymentMethod" value="cash" name="payment-method">
                                        <x-heroicon-o-banknotes class="size-5 shrink-0" aria-hidden="true" />
                                        <span>Cash</span>
                                    </label>
                                    <label class="field-choice">
                                        <input type="radio" wire:model.live="paymentMethod" value="qris" name="payment-method">
                                        <x-heroicon-o-qr-code class="size-5 shrink-0" aria-hidden="true" />
                                        <span>QRIS</span>
                                    </label>
                                </div>
                                @error('paymentMethod') <p class="form-error mt-2" role="alert">{{ $message }}</p> @enderror
                            </fieldset>

                            @if ($paymentMethod === 'qris')
                                <div class="mt-5 pt-5 border-t" style="border-color: var(--line)">
                                    <h3 class="font-medium">Pay with QRIS</h3>
                                    <img src="{{ asset('images/qris/company-qris.png') }}" alt="Agung Farm & Garden QRIS" class="checkout-qris-image mt-3">
                                    <a href="{{ asset('images/qris/company-qris.png') }}" download="agung-farm-qris.png" class="btn btn-ghost mt-3"><x-heroicon-o-arrow-down-tray class="size-4" aria-hidden="true" />Download QRIS</a>
                                    <p class="form-help mt-3">NMID: ID1025456363908 | QR AGUNG FARM AND GARDEN RESORT</p>

                                    <div class="form-field">
                                        <label for="checkout-proof" class="form-label">Upload Payment Proof (optional)</label>
                                        <input id="checkout-proof" type="file" wire:model="proofFile" accept="image/*" class="form-input" aria-describedby="checkout-proof-help" aria-invalid="{{ $errors->has('proofFile') ? 'true' : 'false' }}">
                                        <p id="checkout-proof-help" class="form-help">Image up to 2 MB. Upload it now or from your order details before the deadline.</p>
                                        <p wire:loading wire:target="proofFile" class="form-help" role="status">Uploading payment proof...</p>
                                        @error('proofFile') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            @endif
                        </section>

                        <div class="commerce-panel">
                            <label for="order-notes" class="form-label">Notes (optional)</label>
                            <textarea id="order-notes" wire:model="notes" rows="3" class="form-input mt-2" placeholder="Anything we should know about your order?"></textarea>
                        </div>
                    </div>

                    <aside class="cart-summary mt-5" aria-labelledby="checkout-summary-title">
                        <h2 id="checkout-summary-title" class="text-xl font-medium mb-5">Order Summary</h2>
                        <div class="space-y-4">
                            @foreach ($this->cartItems as $item)
                                @php
                                    $variant = $item->productVariant;
                                    $originalSubtotal = \App\Support\VariantPricing::subtotal($variant->price, $item->quantity, $variant->price_quantity);
                                @endphp
                                <div wire:key="checkout-variant-{{ $variant->id }}" class="checkout-summary-line flex justify-between gap-4 text-sm">
                                    <div class="min-w-0">
                                        <p class="font-medium break-words">{{ $variant->product->name }}</p>
                                        <p class="form-help">{{ $variant->name }} &times; {{ \App\Support\Quantity::format($item->quantity) }} {{ $variant->unit?->name }}</p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        @if ($variant->has_discount)
                                            <span class="product-price-original">{{ \App\Filament\Support\Rupiah::formatWithPrefix($originalSubtotal) }}</span>
                                        @endif
                                        <span>{{ \App\Filament\Support\Rupiah::formatWithPrefix($item->subtotal) }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <dl class="text-sm mt-5 pt-4 border-t space-y-3" style="border-color: var(--line); color: var(--ink-soft)">
                            <div class="flex justify-between gap-4">
                                <dt>Subtotal</dt>
                                <dd>{{ \App\Filament\Support\Rupiah::formatWithPrefix($this->subtotal) }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>Shipping</dt>
                                <dd>{{ \App\Filament\Support\Rupiah::formatWithPrefix($this->shippingCost) }}</dd>
                            </div>
                        </dl>
                        <div class="flex justify-between items-baseline gap-4 mt-5 pt-4 border-t" style="border-color: var(--line)">
                            <span class="font-medium">Total</span>
                            <span class="price-box-value">{{ \App\Filament\Support\Rupiah::formatWithPrefix($this->total) }}</span>
                        </div>

                        @error('itemIds') <p class="form-error mt-4" role="alert">{{ $message }}</p> @enderror

                        <button type="submit" wire:loading.attr="disabled" wire:target="placeOrder,proofFile" class="btn btn-primary w-full mt-5">
                            <span wire:loading.remove wire:target="placeOrder">Place Order</span>
                            <span wire:loading wire:target="placeOrder">Placing your order...</span>
                            <x-heroicon-o-arrow-right class="size-4" aria-hidden="true" />
                        </button>
                        <a href="{{ route('cart') }}" class="btn btn-ghost w-full mt-3">Back to cart</a>
                    </aside>
                </form>
            @endif
        </div>
    </section>
</div>
