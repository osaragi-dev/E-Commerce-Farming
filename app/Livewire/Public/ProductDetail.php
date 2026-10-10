<?php

namespace App\Livewire\Public;

use App\Livewire\Concerns\HasQuickAddToCart;
use App\Models\Cart;
use App\Models\Product;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Product Details — Agung Farm & Garden')]
class ProductDetail extends Component
{
    use HasQuickAddToCart;

    public Product $product;

    public ?string $selectedVariantId = null;

    public int $quantity = 1;

    public function mount(Product $product): void
    {
        // The route's implicit {product:slug} binding only matches the slug —
        // it doesn't know about record_status/is_active, so we re-fetch here
        // scoped by the real lifecycle rules (same pattern as Home/Catalog).
        $this->product = Product::query()
            ->where('slug', $product->slug)
            ->saved()
            ->where('is_active', true)
            ->whereHas('variants', fn ($query) => $query->saved()->where('is_active', true))
            ->with([
                'category.sector',
                'images' => fn ($query) => $query->orderBy('sort_order'),
                'variants' => fn ($query) => $query->saved()->where('is_active', true)->orderBy('price')->orderBy('created_at'),
                'variants.unit',
                'variants.pricePeriods',
                'variants.images' => fn ($query) => $query->orderBy('sort_order'),
                'variants.warehouseStocks.warehouse',
            ])
            ->firstOrFail();

        $this->selectedVariantId = $this->product->variants->first()?->id;
    }

    public function selectVariant(string $variantId): void
    {
        $this->selectedVariantId = $variantId;
        $this->quantity = 1;
        unset($this->imageUrls);
    }

    public function incrementQuantity(): void
    {
        $stock = $this->selectedVariant?->availableForSale() ?? 0;

        if ($this->quantity + 1 <= $stock) {
            $this->quantity++;
        }
    }

    public function decrementQuantity(): void
    {
        if ($this->quantity > 1) {
            $this->quantity--;
        }
    }

    /**
     * "Add to Cart" — variant yang dipilih + quantity saat ini masuk ke
     * cart aktif customer. Kalau variant itu sudah ada di cart, quantity
     * digabung (lihat Cart::addItem()), bukan bikin baris baru.
     *
     * Guest ditolak di sini (bukan lewat route middleware), karena halaman
     * Product Detail sendiri harus tetap bisa dilihat tanpa login — cuma
     * aksi "nambah ke cart"-nya yang butuh akun.
     */
    public function addToCart()
    {
        if (! auth('customer')->check()) {
            return redirect()->route('login');
        }

        $variant = $this->selectedVariant;

        if (! $variant) {
            $this->addError('quantity', 'Please select a variant first.');

            return;
        }

        $cart = Cart::forCustomer(auth('customer')->user());

        // Cek stok terhadap TOTAL setelah digabung dengan yang sudah ada di
        // cart — bukan cuma quantity yang baru diinput — supaya tidak bisa
        // ditembus dengan klik Add to Cart berkali-kali.
        $existingQuantity = (string) $cart->items()->where('product_variant_id', $variant->id)->value('quantity');

        if ($this->quantity < 1 || Quantity::add($existingQuantity ?: 0, $this->quantity) > $variant->availableForSale()) {
            $this->addError('quantity', 'Quantity exceeds available stock.');

            return;
        }

        $cart->addItem($variant, $this->quantity);

        $this->dispatch('cart-updated');
        $this->dispatch('notify', message: "{$this->product->name} was added to your cart.");
    }

    public function openAddToCart(): void
    {
        if (! auth('customer')->check()) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        $variant = $this->selectedVariant;

        if (! $variant) {
            $this->addError('quantity', 'Please select a variant first.');

            return;
        }

        if ($this->quantity < 1 || $this->quantity > $variant->availableForSale()) {
            $this->addError('quantity', 'Quantity exceeds available stock.');

            return;
        }

        $this->dispatch(
            'open-quick-add-modal',
            productId: $this->product->id,
            buyNow: false,
            variantId: $variant->id,
            quantity: $this->quantity,
        );
    }

    /**
     * "Buy Now" — sama seperti Add to Cart, tapi langsung arahkan
     * customer ke halaman Cart untuk lanjut checkout.
     */
    public bool $showBuyNowConfirm = false;

    /**
     * Klik "Buy Now" tidak langsung ke Checkout — muncul popup konfirmasi
     * dulu, biar customer sempat ngecek variant & quantity sebelum beneran
     * pindah halaman.
     */
    public function buyNow(): mixed
    {
        if (! auth('customer')->check()) {
            return redirect()->route('login');
        }

        $variant = $this->selectedVariant;

        if (! $variant) {
            $this->addError('quantity', 'Please select a variant first.');

            return null;
        }

        if ($this->quantity < 1 || $this->quantity > $variant->availableForSale()) {
            $this->addError('quantity', 'Quantity exceeds available stock.');

            return null;
        }

        $this->showBuyNowConfirm = true;

        return null;
    }

    public function confirmBuyNow(): mixed
    {
        $variant = $this->selectedVariant;

        if (! $variant || $this->quantity < 1 || $this->quantity > $variant->availableForSale()) {
            $this->showBuyNowConfirm = false;

            return null;
        }

        // Buy Now itu pembelian mandiri — sengaja TIDAK dicek terhadap
        // quantity yang sudah ada di Cart, dan TIDAK menyentuh Cart sama
        // sekali. Cuma dicek terhadap stok asli.
        return redirect()->route('checkout', ['variant' => $variant->id, 'qty' => $this->quantity]);
    }

    public function cancelBuyNowConfirm(): void
    {
        $this->showBuyNowConfirm = false;
    }

    #[Computed]
    public function sortedVariants()
    {
        // UUID v7 yang dipakai project ini otomatis berurutan sesuai waktu
        // dibuat — jadi urut berdasarkan id = urut berdasarkan kapan Admin
        // nambahin Variant itu (Grade A duluan tetap tampil duluan).
        return $this->product->variants
            ->filter(fn ($variant): bool => $variant->availableForSale() > 0)
            ->sortBy('id')
            ->values();
    }

    #[Computed]
    public function selectedVariant()
    {
        return $this->sortedVariants->firstWhere('id', $this->selectedVariantId)
            ?? $this->sortedVariants->first();
    }

    #[Computed]
    public function priceRange(): array
    {
        $activePrices = $this->product->variants->pluck('effective_price');
        $defaultPrices = $this->product->variants->pluck('price');

        return [
            'min' => $activePrices->min(),
            'max' => $activePrices->max(),
            'original_min' => $defaultPrices->min(),
            'original_max' => $defaultPrices->max(),
            'has_discount' => $this->product->variants->contains(fn ($variant): bool => $variant->has_discount),
        ];
    }

    #[Computed]
    public function imageUrls(): array
    {
        $variant = $this->selectedVariant;

        // Kalau Variant yang dipilih punya foto sendiri, tampilkan itu.
        // Kalau tidak, fallback ke foto umum produk (bukan foto Variant
        // lain — biar tidak nyampur punya variant yang tidak dipilih).
        $images = $variant && $variant->images->isNotEmpty()
            ? $variant->images
            : $this->product->images->whereNull('product_variant_id');

        return $images
            ->map(fn ($image) => Storage::disk('public')->url($image->path))
            ->values()
            ->all();
    }

    #[Computed]
    public function recommendedProducts()
    {
        return Product::query()
            ->with([
                'images' => fn ($query) => $query->orderBy('sort_order'),
                'variants' => fn ($query) => $query->saved()->where('is_active', true)->orderBy('id'),
                'variants.unit',
                'variants.pricePeriods',
                'variants.warehouseStocks.warehouse',
            ])
            ->saved()
            ->where('is_active', true)
            ->whereHas('variants', fn ($query) => $query->saved()->where('is_active', true))
            ->where('category_id', $this->product->category_id)
            ->where('id', '!=', $this->product->id)
            ->take(4)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.public.product-detail');
    }
}
