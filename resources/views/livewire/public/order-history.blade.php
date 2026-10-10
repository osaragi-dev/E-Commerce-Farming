<div class="orders-page">
    <section class="py-8 md:py-12">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <span class="breadcrumb-current" aria-current="page">My Orders</span>
            </nav>

            @if (session('proof_message'))
                <p class="form-success mt-6" role="status">{{ session('proof_message') }}</p>
            @endif
            @if (session('cart_message'))
                <p class="form-error mt-6" role="status">{{ session('cart_message') }}</p>
            @endif

            <div class="account-layout flex gap-6 mt-6">
                <x-account.sidebar />

                <div class="account-main">
                    <div class="mb-8">
                        <p class="eyebrow">Your purchases</p>
                        <h1 class="page-heading mt-2">My Orders</h1>
                        <p class="page-description mt-3">Follow every order from our farm to your door.</p>
                    </div>

                    <div class="order-history-tabs-card">
                        <div class="status-tabs" aria-label="Filter orders by status">
                            @foreach ($this->tabs() as $key => $tabInfo)
                                <button
                                    type="button"
                                    wire:click="$set('tab', '{{ $key }}')"
                                    class="status-tab {{ $tab === $key ? 'is-active' : '' }}"
                                    aria-pressed="{{ $tab === $key ? 'true' : 'false' }}"
                                >
                                    <x-dynamic-component :component="'heroicon-o-'.(['all' => 'squares-2x2', 'waiting' => 'clock', 'packing' => 'cube', 'shipping' => 'truck', 'done' => 'check-circle', 'cancelled' => 'x-circle'][$key])" aria-hidden="true" />
                                    {{ $tabInfo['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="order-history-search-card mt-4">
                        <label for="order-search" class="sr-only">Search orders by order number or product name</label>
                        <div class="search-shell">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-5 h-5 shrink-0 text-[var(--ink-soft)]" aria-hidden="true">
                                <circle cx="11" cy="11" r="7"></circle>
                                <path d="m21 21-4.3-4.3"></path>
                            </svg>
                            <input id="order-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by order number or product name">
                        </div>
                    </div>
                    <p wire:loading wire:target="tab,search" class="form-help mt-3" role="status">Updating your orders...</p>

                    @if ($orders->isEmpty())
                        <div class="empty-state mt-8">
                            <x-heroicon-o-shopping-bag class="order-empty-icon" aria-hidden="true" />
                            <h2>No orders in this category yet</h2>
                            <p>Browse fresh products from our farm, garden, and fisheries.</p>
                            <a href="{{ route('catalog') }}" class="btn btn-primary mt-4">Start Shopping</a>
                        </div>
                    @else
                        <div class="flex flex-col gap-4 mt-6">
                            @foreach ($orders as $order)
                                <article class="order-history-card" wire:key="order-{{ $order->id }}">
                                    <a href="{{ route('orders.show', $order) }}" class="order-history-header flex flex-wrap items-center justify-between gap-3">
                                        <h2 class="text-sm font-medium min-w-0 break-all">{{ $order->order_number }}</h2>
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="status-badge {{ $order->status->getColor() === 'danger' ? 'is-danger' : '' }}">
                                                {{ $this->statusLabel($order->status) }}
                                            </span>
                                            @if ($order->payment?->status === \App\Enums\PaymentStatus::Rejected)
                                                <span class="status-badge is-danger" aria-label="Payment status: Rejected">Rejected</span>
                                            @endif
                                        </span>
                                    </a>
                                    <div class="order-history-meta">
                                        <span><x-heroicon-o-calendar-days aria-hidden="true" />{{ $order->created_at->format('d M Y, H:i') }}</span>
                                        <span><x-heroicon-o-credit-card aria-hidden="true" />{{ ($order->payment?->method ?? $order->payment_method)?->getLabel() ?? 'Not Recorded' }}</span>
                                        <span>
                                            @if ($order->shipping_method === \App\Enums\ShippingMethod::Pickup)
                                                <x-heroicon-o-building-storefront aria-hidden="true" />
                                            @else
                                                <x-heroicon-o-truck aria-hidden="true" />
                                            @endif
                                            {{ $order->shipping_method?->getLabel() ?? 'Not Specified' }}
                                        </span>
                                    </div>

                                    <a href="{{ route('orders.show', $order) }}" class="block">
                                        <div class="mt-4 border-t border-[var(--line)] pt-3">
                                            @foreach ($order->items as $item)
                                                @php $itemImage = $item->product?->images->first(); @endphp
                                                <div class="order-history-item">
                                                    <div class="order-history-item-thumb">
                                                        <x-public.stored-image :path="$itemImage?->path" :alt="$item->product_name" />
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-sm font-medium">{{ $item->product_name }}</p>
                                                        <p class="text-xs text-[var(--ink-soft)]">{{ $item->variant_name }} &middot; x{{ \App\Support\Quantity::format($item->quantity) }}</p>
                                                    </div>
                                                    <div class="order-history-item-price text-sm font-medium">
                                                        {{ \App\Filament\Support\Rupiah::formatWithPrefix($item->subtotal) }}
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </a>

                                    <div class="order-history-total-row flex flex-wrap justify-between items-center gap-2 mt-3 pt-3 border-t border-[var(--line)]">
                                        <span class="text-sm text-[var(--ink-soft)]">Order Total</span>
                                        <span class="order-history-total">{{ \App\Filament\Support\Rupiah::formatWithPrefix($order->total) }}</span>
                                    </div>

                                    <a href="{{ route('orders.show', $order) }}" class="order-details-link">View order details <x-heroicon-o-arrow-right aria-hidden="true" /></a>

                                    @if (in_array($order->status, [\App\Enums\OrderStatus::Done, \App\Enums\OrderStatus::PickedUp], true))
                                        <button
                                            type="button"
                                            wire:click="buyAgain('{{ $order->id }}')"
                                            wire:confirm="Add these items back to your cart?"
                                            wire:loading.attr="disabled"
                                            wire:target="buyAgain"
                                            class="btn btn-ghost w-full mt-4"
                                        >
                                            Buy Again
                                        </button>
                                    @endif
                                </article>
                            @endforeach
                        </div>

                        <div class="mt-6">{{ $orders->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>
