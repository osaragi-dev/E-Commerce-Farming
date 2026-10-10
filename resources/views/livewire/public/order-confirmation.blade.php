<div class="order-detail-page">
    <section class="py-8 md:py-12">
        <div class="wrap order-detail-shell">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('orders.history') }}" class="order-back-link"><x-heroicon-o-arrow-left aria-hidden="true" /> Back to My Orders</a>
            </nav>

            <div class="order-detail-heading mt-6">
                <div class="min-w-0">
                    <p class="eyebrow">Order details</p>
                    <h1 class="page-heading mt-2">{{ $order->order_number }}</h1>
                    <p class="order-placed-at"><x-heroicon-o-calendar-days aria-hidden="true" /> Placed on {{ $order->created_at->format('d M Y, H:i') }}</p>
                </div>
                <div class="order-detail-status">
                    <p class="order-detail-status-label">Order status</p>
                    <span class="status-badge order-detail-status-badge {{ $order->status->getColor() === 'danger' ? 'is-danger' : '' }}">
                        {{ $order->customerStatusLabel() }}
                    </span>
                </div>
            </div>

            @if (session('proof_message'))
                <p class="form-success mt-4" role="status">{{ session('proof_message') }}</p>
            @endif
            @if (session('cart_message'))
                <p class="form-error mt-4" role="status">{{ session('cart_message') }}</p>
            @endif

            @php
                $paymentMethodValue = ($order->payment?->method ?? $order->payment_method)?->value;
                $paymentStatusValue = $order->payment?->status?->value;

                $currentProof = $order->payment?->proofs?->firstWhere('is_current', true);
                $resubmissionDeadline = $currentProof?->resubmission_deadline_at;
                $resubmissionExpired = $resubmissionDeadline !== null
                    && ! $resubmissionDeadline->isFuture();
                $isInitialQrisPayment = $paymentMethodValue === 'qris'
                    && $paymentStatusValue === 'unpaid';
                $initialProofDeadline = $isInitialQrisPayment
                    ? $order->payment?->proof_upload_deadline_at
                    : null;
                $initialProofExpired = $initialProofDeadline !== null
                    && ! $initialProofDeadline->isFuture();
                $isRejectedPayment = $paymentMethodValue === 'qris'
                    && $paymentStatusValue === 'rejected';
                $canResubmitPaymentProof = $order->status !== \App\Enums\OrderStatus::Cancelled
                    && ! $resubmissionExpired;
                $canUploadInitialProof = $order->status !== \App\Enums\OrderStatus::Cancelled
                    && ! $initialProofExpired;
            @endphp

            @if ($isInitialQrisPayment)
                <div class="payment-rejection-alert payment-proof-required-alert mt-6" role="alert" aria-labelledby="payment-proof-required-title">
                    <div class="payment-rejection-alert-header">
                        <span class="payment-rejection-alert-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 9v3.75m9.303 3.376L13.303 4.5a1.5 1.5 0 0 0-2.606 0l-8 13.856A1.5 1.5 0 0 0 4 20.606h16a1.5 1.5 0 0 0 1.303-2.23Z"></path>
                                <path d="M12 16.5h.008v.008H12V16.5Z"></path>
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p id="payment-proof-required-title" class="payment-rejection-alert-title">Payment Proof Required</p>
                            <p class="payment-rejection-alert-reason">Upload your QRIS payment proof before this order can be processed.</p>
                        </div>
                    </div>

                    @if ($canUploadInitialProof)
                        <p class="payment-rejection-alert-deadline">
                            Upload your payment proof before
                            @if ($initialProofDeadline)
                                <strong>{{ $initialProofDeadline->copy()->timezone('Asia/Jakarta')->format('d-m-Y H:i') }} (Asia/Jakarta)</strong>.
                            @else
                                <strong>the upload window closes</strong>.
                            @endif
                        </p>
                        <a href="#payment-proof-upload" class="btn btn-primary payment-rejection-alert-action">Upload Payment Proof</a>
                    @else
                        <p class="payment-rejection-alert-expired">The payment proof upload window has expired. This order has been cancelled.</p>
                    @endif
                </div>
            @endif

            @if ($isRejectedPayment)
                <div class="payment-rejection-alert mt-6" role="alert" aria-labelledby="payment-rejection-title">
                    <div class="payment-rejection-alert-header">
                        <span class="payment-rejection-alert-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 9v3.75m9.303 3.376L13.303 4.5a1.5 1.5 0 0 0-2.606 0l-8 13.856A1.5 1.5 0 0 0 4 20.606h16a1.5 1.5 0 0 0 1.303-2.23Z"></path>
                                <path d="M12 16.5h.008v.008H12V16.5Z"></path>
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <p id="payment-rejection-title" class="payment-rejection-alert-title">Payment Rejected</p>
                            <p class="payment-rejection-alert-reason">{{ $order->payment?->rejection_reason ?? 'Your payment proof could not be verified.' }}</p>
                        </div>
                    </div>

                    @if ($canResubmitPaymentProof)
                        <p class="payment-rejection-alert-deadline">
                            Action required. Upload a new payment proof before
                            @if ($resubmissionDeadline)
                                <strong>{{ $resubmissionDeadline->copy()->timezone('Asia/Jakarta')->format('d-m-Y H:i') }} (Asia/Jakarta)</strong>.
                            @else
                                <strong>the resubmission window closes</strong>.
                            @endif
                        </p>
                        <a href="#payment-proof-upload" class="btn btn-primary payment-rejection-alert-action">Upload New Payment Proof</a>
                    @else
                        <p class="payment-rejection-alert-expired">The payment proof upload window has expired. This order has been cancelled.</p>
                    @endif
                </div>
            @endif

            <section class="order-progress-panel mt-6" aria-labelledby="order-progress-title">
                <h2 id="order-progress-title" class="order-section-heading"><x-heroicon-o-cube aria-hidden="true" /> Order progress</h2>
                <div class="order-timeline" aria-label="Order progress">
                    @foreach ($this->timeline as $index => $step)
                        <div class="order-timeline-step {{ $step['done'] ? 'is-done' : '' }}">
                            <div class="order-timeline-dot" aria-hidden="true">
                                @switch($step['icon'])
                                    @case('document')
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                            <path d="M14 2v6h6"></path>
                                            <path d="M9 13h6M9 17h6"></path>
                                        </svg>
                                        @break
                                    @case('wallet')
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-1"></path>
                                            <path d="M16 12h5v4h-5a2 2 0 0 1 0-4z"></path>
                                        </svg>
                                        @break
                                    @case('truck')
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 3h13v13H1z"></path>
                                            <path d="M14 8h4l4 4v4h-8z"></path>
                                            <circle cx="6" cy="18" r="2"></circle>
                                            <circle cx="17" cy="18" r="2"></circle>
                                        </svg>
                                        @break
                                    @case('check')
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M20 6 9 17l-5-5"></path>
                                        </svg>
                                        @break
                                    @case('cancel')
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M18 6 6 18M6 6l12 12"></path>
                                        </svg>
                                        @break
                                @endswitch
                            </div>
                            <p class="order-timeline-label">{{ $step['label'] }}</p>
                            <p class="order-timeline-time">{{ $step['time']?->format('d-m-Y H:i') ?? '—' }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="order-detail-grid mt-6">
                <div class="content-panel order-products-panel">
                    <h2 class="order-section-heading"><x-heroicon-o-shopping-bag aria-hidden="true" /> Products <span class="order-item-count">{{ $order->items->count() }} {{ \Illuminate\Support\Str::plural('item', $order->items->count()) }}</span></h2>
                    @foreach ($order->items as $item)
                        <div class="order-history-item order-detail-item">
                            <div class="order-history-item-thumb">
                                <x-public.stored-image :path="$item->product?->images->first()?->path" :alt="$item->product_name" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">{{ $item->product_name }}</p>
                                <p class="text-xs text-[var(--ink-soft)] mt-1">{{ $item->variant_name }} &middot; Qty {{ \App\Support\Quantity::format($item->quantity) }}</p>
                            </div>
                            <span class="order-history-item-price text-sm font-medium">{{ \App\Filament\Support\Rupiah::formatWithPrefix($item->subtotal) }}</span>
                        </div>
                    @endforeach

                    <div class="mt-3 border-t border-[var(--line)] pt-4">
                        <div class="order-summary-row text-sm">
                            <span>Subtotal</span>
                            <span>{{ \App\Filament\Support\Rupiah::formatWithPrefix($order->subtotal) }}</span>
                        </div>
                        <div class="order-summary-row text-sm mt-1">
                            <span>Discount</span>
                            <span>-{{ \App\Filament\Support\Rupiah::formatWithPrefix($order->discount_amount) }}</span>
                        </div>
                        <div class="order-summary-row text-sm mt-1">
                            <span>Shipping Cost</span>
                            <span>{{ \App\Filament\Support\Rupiah::formatWithPrefix($order->shipping_cost) }}</span>
                        </div>
                        <div class="order-summary-row items-baseline mt-3">
                            <span class="font-medium">Total</span>
                            <span class="price-box-value">{{ \App\Filament\Support\Rupiah::formatWithPrefix($order->total) }}</span>
                        </div>
                    </div>
                </div>

                <div class="order-detail-information">
                    <div class="content-panel">
                        <h2 class="order-section-heading"><x-heroicon-o-truck aria-hidden="true" /> Delivery &amp; Pickup</h2>
                        <div class="order-summary-row text-sm">
                            <span>Shipping Method</span>
                            <span>{{ $order->shipping_method?->getLabel() ?? 'Not Specified' }}</span>
                        </div>
                        <div class="order-contact-line"><x-heroicon-o-user aria-hidden="true" /><span>{{ $order->recipient_name }}</span></div>
                        <div class="order-contact-line"><x-heroicon-o-phone aria-hidden="true" /><span>{{ $order->recipient_phone }}</span></div>
                        <div class="order-address-block">
                            <p class="order-address-label"><x-heroicon-o-map-pin aria-hidden="true" />{{ $order->shipping_method?->getLocationLabel() ?? 'Shipping Location' }}</p>
                            <p>{{ $order->shipping_address ?? '—' }}</p>
                        </div>
                    </div>

                    <div class="content-panel mt-4">
                        <h2 class="order-section-heading"><x-heroicon-o-credit-card aria-hidden="true" /> Payment</h2>
                        <div class="order-summary-row text-sm">
                            <span>Payment Method</span>
                            <span>{{ ($order->payment?->method ?? $order->payment_method)?->getLabel() ?? 'Not Recorded' }}</span>
                        </div>
                        <div class="order-summary-row text-sm mt-1">
                            <span>Payment Status</span>
                            <span>{{ $order->customerPaymentStatusLabel() }}</span>
                        </div>
                    </div>
                </div>
            </div>

            @if ($order->canBeCancelledByCustomer())
                <div class="content-panel mt-4">
                    <button type="button" wire:click="cancelOrder" wire:confirm="Cancel this order?" wire:loading.attr="disabled" wire:target="cancelOrder" class="btn btn-ghost w-full">Cancel Order</button>
                </div>
            @endif

            @if ($isInitialQrisPayment && $canUploadInitialProof)
                <div id="payment-proof-upload" class="content-panel mt-4">
                    <h2 class="order-section-heading"><x-heroicon-o-arrow-up-tray aria-hidden="true" /> Upload Payment Proof</h2>
                    <img src="{{ asset('images/qris/company-qris.png') }}" alt="Agung Farm & Garden QRIS" class="payment-proof-image mt-3">
                    <p class="text-xs mt-2 text-[var(--ink-soft)]">NMID: ID1025456363908 | QR AGUNG FARM AND GARDEN RESORT</p>

                    <form wire:submit="submitProof" class="mt-4">
                        <div class="form-field">
                            <label for="order-proof" class="form-label">Upload Payment Proof</label>
                            <input id="order-proof" type="file" wire:model="proofFile" accept="image/*" class="form-input" required aria-describedby="order-proof-help" @error('proofFile') aria-invalid="true" @enderror>
                            <p id="order-proof-help" class="form-help">Upload a clear image. Maximum size 2 MB.</p>
                            <p wire:loading wire:target="proofFile" class="form-help" role="status">Uploading payment proof...</p>
                            @error('proofFile') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary mt-4" wire:loading.attr="disabled" wire:target="proofFile,submitProof">
                            <span wire:loading.remove wire:target="submitProof">Send Payment Proof</span>
                            <span wire:loading wire:target="submitProof">Sending...</span>
                        </button>
                    </form>
                </div>
            @elseif ($paymentMethodValue === 'qris' && $paymentStatusValue === 'waiting_confirm' && $currentProof === null)
                <div class="content-panel mt-4">
                    <h2 class="order-section-heading"><x-heroicon-o-qr-code aria-hidden="true" /> Pay with QRIS</h2>
                    <img src="{{ asset('images/qris/company-qris.png') }}" alt="Agung Farm & Garden QRIS" class="payment-proof-image mt-3">
                    <p class="text-xs mt-2 text-[var(--ink-soft)]">NMID: ID1025456363908 | QR AGUNG FARM AND GARDEN RESORT</p>

                    <form wire:submit="submitProof" class="mt-4">
                        <div class="form-field">
                            <label for="order-proof" class="form-label">Upload Payment Proof</label>
                            <input id="order-proof" type="file" wire:model="proofFile" accept="image/*" class="form-input" required aria-describedby="order-proof-help" @error('proofFile') aria-invalid="true" @enderror>
                            <p id="order-proof-help" class="form-help">Upload a clear image. Maximum size 2 MB.</p>
                            <p wire:loading wire:target="proofFile" class="form-help" role="status">Uploading payment proof...</p>
                            @error('proofFile') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary mt-4" wire:loading.attr="disabled" wire:target="proofFile,submitProof">
                            <span wire:loading.remove wire:target="submitProof">Send Payment Proof</span>
                            <span wire:loading wire:target="submitProof">Sending...</span>
                        </button>
                    </form>
                </div>
            @elseif ($paymentMethodValue === 'qris' && $paymentStatusValue === 'waiting_confirm')
                <div class="content-panel mt-4">
                    <h2 class="order-section-heading"><x-heroicon-o-check-circle aria-hidden="true" /> Payment Proof Submitted</h2>
                    <p class="text-sm mt-1 text-[var(--ink-soft)]">Your payment proof has been received and is awaiting verification by Admin.</p>

                    @if ($currentProof->file_path)
                        <x-public.stored-image :path="$currentProof->file_path" alt="Uploaded payment proof" class="payment-proof-image mt-3" />
                    @endif

                    @if ($currentProof->uploaded_at)
                        <p class="text-xs mt-2 text-[var(--ink-soft)]">Uploaded on {{ $currentProof->uploaded_at->format('d-m-Y H:i') }}</p>
                    @endif
                </div>
            @elseif ($paymentMethodValue === 'qris' && $paymentStatusValue === 'rejected' && $canResubmitPaymentProof)
                <div id="payment-proof-upload" class="content-panel mt-4">
                    <h2 class="order-section-heading"><x-heroicon-o-arrow-up-tray aria-hidden="true" /> Upload Replacement Payment Proof</h2>
                    <img src="{{ asset('images/qris/company-qris.png') }}" alt="Agung Farm & Garden QRIS" class="payment-proof-image mt-3">
                    <p class="text-xs mt-2 text-[var(--ink-soft)]">NMID: ID1025456363908 | QR AGUNG FARM AND GARDEN RESORT</p>

                    <form wire:submit="submitProof" class="mt-4">
                        <div class="form-field">
                            <label for="order-proof" class="form-label">Upload New Payment Proof</label>
                            <input id="order-proof" type="file" wire:model="proofFile" accept="image/*" class="form-input" required aria-describedby="order-proof-help" @error('proofFile') aria-invalid="true" @enderror>
                            <p id="order-proof-help" class="form-help">Upload a clear image. Maximum size 2 MB.</p>
                            <p wire:loading wire:target="proofFile" class="form-help" role="status">Uploading payment proof...</p>
                            @error('proofFile') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary mt-4" wire:loading.attr="disabled" wire:target="proofFile,submitProof">
                            <span wire:loading.remove wire:target="submitProof">Send Payment Proof</span>
                            <span wire:loading wire:target="submitProof">Sending...</span>
                        </button>
                    </form>
                </div>
            @elseif ($paymentStatusValue === 'unpaid_cancel')
                <div class="content-panel mt-4">
                    <h2 class="text-lg font-medium">Payment Cancelled</h2>
                    <p class="text-sm mt-1 text-[var(--ink-soft)]">The QRIS payment proof was not uploaded before the deadline, so this order has been cancelled.</p>
                    <a href="{{ route('orders.history') }}" class="btn btn-ghost w-full mt-3">View My Orders</a>
                </div>
            @elseif ($paymentStatusValue === 'void')
                <div class="content-panel mt-4">
                    <h2 class="text-lg font-medium">Payment Voided</h2>
                    <p class="text-sm mt-1 text-[var(--ink-soft)]">This payment has been canceled. Please contact Admin if you believe this is a mistake.</p>
                    <a href="{{ route('orders.history') }}" class="btn btn-ghost w-full mt-3">View My Orders</a>
                </div>
            @elseif ($paymentMethodValue === 'cash')
                <div class="content-panel mt-4">
                    <p class="text-sm text-[var(--ink-soft)]">Payment Status: {{ $order->paymentStageLabel() }}.
                        @if ($paymentStatusValue === 'paid')
                            Your cash payment has been received and confirmed.
                        @elseif ($paymentStatusValue === 'unpaid')
                            Your order can be processed while payment is unpaid. Please prepare exact cash when the order is delivered.
                        @else
                            Please prepare exact cash when the order is delivered. Status will update once confirmed by Admin.
                        @endif
                    </p>
                    <a href="{{ route('orders.history') }}" class="btn btn-ghost w-full mt-3">View My Orders</a>
                </div>
            @endif

            @if (in_array($order->status, [\App\Enums\OrderStatus::Done, \App\Enums\OrderStatus::PickedUp], true))
                <button type="button" wire:click="buyAgain" wire:confirm="Add these items back to your cart?" wire:loading.attr="disabled" wire:target="buyAgain" class="btn btn-ghost w-full mt-4">
                    Buy Again
                </button>
            @endif

            <a href="{{ route('catalog') }}" class="btn btn-ghost w-full mt-4">Continue Shopping</a>
        </div>
    </section>
</div>
