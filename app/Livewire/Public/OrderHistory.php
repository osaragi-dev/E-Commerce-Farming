<?php

namespace App\Livewire\Public;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('My Orders — Agung Farm & Garden')]
class OrderHistory extends Component
{
    use WithPagination;

    private const PER_PAGE = 10;

    /** Key => label yang tampil di tab, dan status OrderStatus mana saja yang termasuk di dalamnya. */
    private const TABS = [
        'all' => ['label' => 'All', 'statuses' => null],
        'waiting' => ['label' => 'Waiting', 'statuses' => [
            OrderStatus::WaitingPayment,
            OrderStatus::WaitingConfirmation,
            OrderStatus::ReadyToProcess,
        ]],
        'packing' => ['label' => 'Packing', 'statuses' => [OrderStatus::Packing]],
        'shipping' => ['label' => 'Shipping', 'statuses' => [OrderStatus::Shipping, OrderStatus::ReadyForPickup]],
        'done' => ['label' => 'Done', 'statuses' => [OrderStatus::Done, OrderStatus::PickedUp]],
        'cancelled' => ['label' => 'Cancelled', 'statuses' => [OrderStatus::Cancelled]],
    ];

    private const TAB_ALIASES = [
        'waiting_confirmation' => 'waiting',
        'processing' => 'waiting',
        'picking' => 'packing',
    ];

    /**
     * Return customer-facing status label without changing internal admin labels.
     */
    public function statusLabel(OrderStatus $status): string
    {
        foreach (self::TABS as $tab) {
            if (in_array($status, $tab['statuses'] ?? [], true)) {
                return $tab['label'];
            }
        }

        return $status->getLabel();
    }

    #[Url(as: 'status', history: true)]
    public string $tab = 'all';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public function mount(): void
    {
        $this->tab = $this->normalizedTab();
    }

    public function updatedTab(): void
    {
        $this->tab = $this->normalizedTab();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * "Beli Lagi" — ambil semua item di order LAMA ini, masukkan lagi ke
     * Cart yang AKTIF sekarang (bukan bikin order baru langsung). Variant
     * yang sudah tidak aktif/stoknya habis dilewati, bukan bikin error.
     */
    public function buyAgain(string $orderId): mixed
    {
        $order = Order::query()
            ->with(['items.productVariant.warehouseStocks.warehouse'])
            ->where('customer_id', auth('customer')->id())
            ->findOrFail($orderId);

        $cart = Cart::forCustomer(auth('customer')->user());
        $addedItemIds = [];

        foreach ($order->items as $item) {
            $variant = $item->productVariant;

            if (! $variant || ! $variant->is_active || $variant->availableForSale() <= 0) {
                continue;
            }

            $quantity = min($item->quantity, $variant->availableForSale());
            $addedItemIds[] = $cart->addItem($variant, $quantity)->id;
        }

        if ($addedItemIds === []) {
            session()->flash('cart_message', 'Products from this order are no longer available.');

            return null;
        }

        $this->dispatch('cart-updated');

        return redirect()->route('cart', ['selected' => implode(',', $addedItemIds)]);
    }

    public function tabs(): array
    {
        return self::TABS;
    }

    public function render(): View
    {
        $statuses = self::TABS[$this->tab]['statuses'] ?? null;

        $keyword = trim($this->search);

        $orders = Order::query()
            ->with(['items.product.images', 'payment'])
            ->where('customer_id', auth('customer')->id())
            ->when($statuses !== null, fn ($query) => $query->whereIn('status', $statuses))
            ->when($keyword !== '', fn ($query) => $query->where(function ($query) use ($keyword) {
                $query->whereRaw('LOWER(order_number) LIKE ?', ['%'.mb_strtolower($keyword).'%'])
                    ->orWhereHas('items', fn ($query) => $query->whereRaw('LOWER(product_name) LIKE ?', ['%'.mb_strtolower($keyword).'%']));
            }))
            ->latest('created_at')
            ->paginate(self::PER_PAGE);

        return view('livewire.public.order-history', ['orders' => $orders]);
    }

    private function normalizedTab(): string
    {
        return self::TAB_ALIASES[$this->tab]
            ?? (array_key_exists($this->tab, self::TABS) ? $this->tab : 'all');
    }
}
