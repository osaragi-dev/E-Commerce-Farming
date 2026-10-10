<?php

namespace App\Livewire\Concerns;

use App\Models\Product;

/**
 * Dipakai di komponen manapun yang nampilin <x-public.product-card> dan
 * perlu nanganin klik tombol "+" / "Buy"-nya (Catalog, Home, ProductDetail).
 *
 * Klik "+" atau "Buy" SELALU buka popup (QuickAddModal) — tidak ada lagi
 * jalur pintas "langsung eksekusi" untuk produk 1-variant, karena popup-nya
 * sekarang juga dipakai buat atur Quantity, bukan cuma pilih Variant.
 */
trait HasQuickAddToCart
{
    public function quickAdd(string $productId): void
    {
        if (! auth('customer')->check()) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        if (! $this->productHasAvailableVariant($productId)) {
            return;
        }

        $this->dispatch('open-quick-add-modal', productId: $productId, buyNow: false);
    }

    public function quickBuyNow(string $productId): void
    {
        if (! auth('customer')->check()) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        if (! $this->productHasAvailableVariant($productId)) {
            return;
        }

        $this->dispatch('open-quick-add-modal', productId: $productId, buyNow: true);
    }

    private function productHasAvailableVariant(string $productId): bool
    {
        $product = Product::query()
            ->with([
                'variants' => fn ($query) => $query->saved()->where('is_active', true),
                'variants.warehouseStocks.warehouse',
            ])
            ->whereKey($productId)
            ->first();

        return $product?->variants->contains(
            fn ($variant): bool => $variant->availableForSale() > 0,
        ) ?? false;
    }
}
