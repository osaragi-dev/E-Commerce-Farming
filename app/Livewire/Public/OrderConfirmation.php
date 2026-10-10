<?php

namespace App\Livewire\Public;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderStatusLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Order Details — Agung Farm & Garden')]
class OrderConfirmation extends Component
{
    use WithFileUploads;

    public Order $order;

    public $proofFile = null;

    public function mount(Order $order): void
    {
        abort_unless($order->customer_id === auth('customer')->id(), 403);

        $order->load([
            'items.product.images',
            'items.productVariant.warehouseStocks.warehouse',
            'payment.proofs',
        ]);
        if ($order->payment?->status === PaymentStatus::Rejected) {
            $order->payment->cancelIfResubmissionExpired();
        } elseif ($order->payment?->status === PaymentStatus::Unpaid
            && $order->payment?->method === PaymentMethod::Qris) {
            $order->payment->cancelIfInitialProofExpired();
        }

        $this->order = $order->fresh()->load([
            'items.product.images',
            'items.productVariant.warehouseStocks.warehouse',
            'payment.proofs',
        ]);
    }

    public function submitProof(): mixed
    {
        abort_unless($this->order->customer_id === auth('customer')->id(), 403);
        abort_unless($this->order->payment !== null, 404);

        $payment = $this->order->payment->refresh();
        abort_unless($payment->method === PaymentMethod::Qris
            && in_array($payment->status, [PaymentStatus::Unpaid, PaymentStatus::WaitingConfirm, PaymentStatus::Rejected, PaymentStatus::Void], true), 403);
        if ($payment->status === PaymentStatus::Rejected) {
            $payment->cancelIfResubmissionExpired();
        } elseif ($payment->status === PaymentStatus::Unpaid
            && $payment->method === PaymentMethod::Qris) {
            if ($payment->cancelIfInitialProofExpired()) {
                session()->flash('proof_message', 'The payment proof upload window has expired. This order has been cancelled.');

                return redirect()->route('orders.history');
            }
        }
        $payment->refresh();

        if (! $payment->canSubmitProof()) {
            session()->flash('proof_message', 'The payment proof upload window has expired. This order has been cancelled.');

            return redirect()->route('orders.history');
        }

        $this->validate([
            'proofFile' => ['required', 'image', 'max:2048'],
        ]);

        $path = $this->proofFile->storeAs(
            'payment-proofs',
            $this->order->order_number.'.'.$this->proofFile->getClientOriginalExtension(),
            'public',
        );

        try {
            $payment->submitProof($path);
        } catch (\RuntimeException $exception) {
            Storage::disk('public')->delete($path);

            if (
                $payment->cancelIfResubmissionExpired()
                || $payment->cancelIfInitialProofExpired()
                || $this->order->refresh()->status === OrderStatus::Cancelled
            ) {
                session()->flash('proof_message', 'The payment proof upload window has expired. This order has been cancelled.');

                return redirect()->route('orders.history');
            }

            throw $exception;
        }

        session()->flash('proof_message', 'Payment proof uploaded. Our team will verify it shortly.');

        return redirect()->route('orders.history');
    }

    public function cancelOrder(): mixed
    {
        abort_unless($this->order->customer_id === auth('customer')->id(), 403);

        if (! $this->order->canBeCancelledByCustomer()) {
            return null;
        }

        $this->order->cancel(null, 'Canceled by customer.');

        return redirect()->route('orders.history');
    }

    public function buyAgain(): mixed
    {
        $order = $this->order->load('items.productVariant.warehouseStocks.warehouse');
        $cart = Cart::forCustomer(auth('customer')->user());
        $addedItemIds = [];

        foreach ($order->items as $item) {
            $variant = $item->productVariant;

            if (! $variant || ! $variant->is_active || $variant->availableForSale() <= 0) {
                continue;
            }

            $addedItemIds[] = $cart->addItem($variant, min($item->quantity, $variant->availableForSale()))->id;
        }

        if ($addedItemIds === []) {
            session()->flash('cart_message', 'Products from this order are no longer available.');

            return null;
        }

        $this->dispatch('cart-updated');

        return redirect()->route('cart', ['selected' => implode(',', $addedItemIds)]);
    }

    #[Computed]
    public function timeline(): array
    {
        $order = $this->order;

        $logs = OrderStatusLog::query()
            ->where('order_id', $order->id)
            ->get()
            ->keyBy(fn ($log) => $log->to_status->value);

        if ($order->status === OrderStatus::Cancelled) {
            return [
                ['label' => 'Waiting', 'icon' => 'document', 'time' => $order->created_at, 'done' => true],
                ['label' => 'Cancelled', 'icon' => 'cancel', 'time' => $logs->get('cancelled')?->created_at, 'done' => true],
            ];
        }

        $packingAt = $logs->get('packing')?->created_at;
        $shippingAt = $logs->get('shipping')?->created_at ?? $logs->get('ready_for_pickup')?->created_at;
        $completedAt = $logs->get('done')?->created_at ?? $logs->get('picked_up')?->created_at;

        return [
            ['label' => 'Waiting', 'icon' => 'document', 'time' => $order->created_at, 'done' => true],
            ['label' => 'Packing', 'icon' => 'document', 'time' => $packingAt, 'done' => $packingAt !== null],
            ['label' => 'Shipping', 'icon' => 'truck', 'time' => $shippingAt, 'done' => $shippingAt !== null],
            ['label' => 'Done', 'icon' => 'check', 'time' => $completedAt, 'done' => $completedAt !== null],
        ];
    }

    public function render(): View
    {
        return view('livewire.public.order-confirmation');
    }
}
