<?php

namespace App\Livewire\Public;

use App\Models\Cart as CartModel;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Shopping Cart — Agung Farm & Garden')]
class Cart extends Component
{
    /** @var array<int, string> */
    public array $selectedItemIds = [];

    public function mount(): void
    {
        CartModel::forCustomer(auth('customer')->user());

        $requestedIds = array_values(array_filter(explode(',', (string) request()->query('selected', ''))));
        $availableIds = $this->items->pluck('id')->all();

        $this->selectedItemIds = $requestedIds === []
            ? $availableIds
            : array_values(array_intersect($requestedIds, $availableIds));
    }

    public function toggleSelectAll(): void
    {
        $this->selectedItemIds = count($this->selectedItemIds) === $this->items->count()
            ? []
            : $this->items->pluck('id')->all();
    }

    public function incrementQuantity(string $cartItemId): void
    {
        $item = $this->findOwnedItem($cartItemId);

        if (! $item || Quantity::add($item->quantity, 1) > $item->productVariant->availableForSale()) {
            return;
        }

        $item->update(['quantity' => Quantity::add($item->quantity, 1)]);
        $this->dispatch('cart-updated');
        $this->refreshCart();
    }

    public function decrementQuantity(string $cartItemId): void
    {
        $item = $this->findOwnedItem($cartItemId);

        if (! $item || $item->quantity <= 1) {
            return;
        }

        $item->update(['quantity' => Quantity::subtract($item->quantity, 1)]);
        $this->dispatch('cart-updated');
        $this->refreshCart();
    }

    public function removeItem(string $cartItemId): void
    {
        $this->findOwnedItem($cartItemId)?->delete();
        $this->selectedItemIds = array_values(array_diff($this->selectedItemIds, [$cartItemId]));
        $this->dispatch('cart-updated');
        $this->refreshCart();
    }

    public function changeVariant(string $cartItemId, string $variantId): void
    {
        $errorKey = 'variant.'.$cartItemId;
        $this->resetErrorBag($errorKey);

        if (! Str::isUuid($cartItemId) || ! Str::isUuid($variantId)) {
            $this->addError($errorKey, 'Choose an available variant for this product.');

            return;
        }

        $changed = DB::transaction(function () use ($cartItemId, $variantId, $errorKey): bool {
            $cart = CartModel::query()->whereKey($this->cart->id)->lockForUpdate()->firstOrFail();
            $items = $cart->items()->with('productVariant')->orderBy('id')->lockForUpdate()->get();
            $item = $items->firstWhere('id', $cartItemId);
            $variant = $item ? ProductVariant::query()->inventoryVisible()
                ->where('product_id', $item->productVariant?->product_id)
                ->with('warehouseStocks.warehouse')
                ->find($variantId) : null;

            if (! $variant) {
                $this->addError($errorKey, 'Choose an available variant for this product.');

                return false;
            }

            if ($item->product_variant_id === $variant->id) {
                return false;
            }

            $existing = $items->firstWhere('product_variant_id', $variant->id);
            $quantity = Quantity::add($item->quantity, $existing?->quantity ?? 0);
            $available = $variant->availableForSale();

            if ($quantity > $available) {
                $this->addError($errorKey, "Only {$available} available for this variant. Reduce the quantity or choose another variant.");

                return false;
            }

            if ($existing) {
                $existing->update(['quantity' => $quantity]);
                $item->delete();

                if (in_array($item->id, $this->selectedItemIds, true)) {
                    $this->selectedItemIds[] = $existing->id;
                }

                $this->selectedItemIds = array_values(array_unique(array_diff($this->selectedItemIds, [$item->id])));
            } else {
                $item->update(['product_variant_id' => $variant->id]);
            }

            return true;
        });

        $this->refreshCart();

        if ($changed) {
            $this->dispatch('cart-updated');
        }
    }

    /**
     * Selalu cek dulu item itu BENERAN milik cart customer yang sedang
     * login, sebelum diubah/dihapus — cartItemId datang dari client
     * (wire:click), jadi tidak boleh dipercaya begitu saja.
     */
    private function findOwnedItem(string $cartItemId): ?CartItem
    {
        return $this->items->firstWhere('id', $cartItemId);
    }

    private function refreshCart(): void
    {
        unset($this->items, $this->selectedItems, $this->subtotal);
    }

    #[Computed]
    public function cart(): CartModel
    {
        return CartModel::forCustomer(auth('customer')->user());
    }

    #[Computed]
    public function items()
    {
        return $this->cart->items()
            ->with([
                'productVariant.product.images',
                'productVariant.product.variants' => fn ($query) => $query->inventoryVisible()->orderBy('name'),
                'productVariant.product.variants.unit',
                'productVariant.unit',
                'productVariant.pricePeriods',
                'productVariant.warehouseStocks.warehouse',
            ])
            ->get();
    }

    #[Computed]
    public function selectedItems()
    {
        return $this->items->whereIn('id', $this->selectedItemIds);
    }

    #[Computed]
    public function subtotal(): float
    {
        return $this->selectedItems->sum->subtotal;
    }

    public function render(): View
    {
        return view('livewire.public.cart');
    }
}
