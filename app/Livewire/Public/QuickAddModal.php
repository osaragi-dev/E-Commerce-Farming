<?php

namespace App\Livewire\Public;

use App\Models\Cart;
use App\Models\Product;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class QuickAddModal extends Component
{
    public bool $show = false;

    public ?string $productId = null;

    public ?string $selectedVariantId = null;

    public bool $buyNow = false;

    public int $quantity = 1;

    #[On('open-quick-add-modal')]
    public function open(string $productId, bool $buyNow = false, ?string $variantId = null, int $quantity = 1): void
    {
        $this->productId = $productId;
        $this->buyNow = $buyNow;
        $this->quantity = max(1, $quantity);
        $this->show = true;

        $this->resetValidation();

        // Kalau cuma ada 1 pilihan Variant, langsung pilihkan otomatis
        // (tidak perlu bikin customer klik chip yang cuma ada 1).
        $variants = $this->product?->variants;
        $selectedVariant = $variantId
            ? $variants?->firstWhere('id', $variantId)
            : null;

        $this->selectedVariantId = $selectedVariant?->id
            ?? ($variants && $variants->count() === 1 ? $variants->first()->id : null);

        if ($selectedVariant) {
            $this->quantity = min($this->quantity, (int) floor((float) $selectedVariant->availableForSale()));
        }
    }

    public function selectVariant(string $variantId): void
    {
        $this->selectedVariantId = $variantId;
        $this->quantity = 1;
    }

    public function incrementQuantity(): void
    {
        $variant = $this->selectedVariantObject();

        if ($variant && $this->quantity + 1 <= $variant->availableForSale()) {
            $this->quantity++;
        }
    }

    public function decrementQuantity(): void
    {
        if ($this->quantity > 1) {
            $this->quantity--;
        }
    }

    public function close(): void
    {
        $this->show = false;
        $this->productId = null;
        $this->selectedVariantId = null;
        $this->quantity = 1;
    }

    public function confirmAdd(): mixed
    {
        $variant = $this->selectedVariantObject();

        if (! $variant || $variant->availableForSale() < 1) {
            return null;
        }

        if ($this->quantity > $variant->availableForSale()) {
            $this->addError('quantity', 'Quantity exceeds available stock.');

            return null;
        }

        if (! $this->buyNow) {
            $existingQuantity = (string) Cart::forCustomer(auth('customer')->user())
                ->items()
                ->where('product_variant_id', $variant->id)
                ->value('quantity');

            if (Quantity::add($existingQuantity ?: 0, $this->quantity) > $variant->availableForSale()) {
                $this->addError('quantity', 'Quantity exceeds available stock.');

                return null;
            }
        }

        if ($this->buyNow) {
            $quantity = $this->quantity;
            $this->close();

            return redirect()->route('checkout', ['variant' => $variant->id, 'qty' => $quantity]);
        }

        Cart::forCustomer(auth('customer')->user())->addItem($variant, $this->quantity);

        $this->dispatch('cart-updated');
        $this->dispatch('notify', message: "{$this->product->name} added to cart.");

        $this->close();

        return null;
    }

    private function selectedVariantObject()
    {
        return $this->product?->variants->firstWhere('id', $this->selectedVariantId);
    }

    #[Computed]
    public function product(): ?Product
    {
        if (! $this->productId) {
            return null;
        }

        return Product::query()
            ->with([
                'variants' => fn ($query) => $query
                    ->saved()
                    ->where('is_active', true)
                    ->orderBy('price'),
                'variants.unit',
                'variants.pricePeriods',
                'variants.warehouseStocks.warehouse',
            ])
            ->saved()
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->saved()->where('is_active', true))
            ->find($this->productId);
    }

    public function render(): View
    {
        return view('livewire.public.quick-add-modal');
    }
}
