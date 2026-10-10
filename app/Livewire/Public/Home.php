<?php

namespace App\Livewire\Public;

use App\Enums\OrderStatus;
use App\Livewire\Concerns\HasQuickAddToCart;
use App\Models\CompanyProfile;
use App\Models\Product;
use App\Models\ProductDisplaySetting;
use App\Models\Sector;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Agung Farm & Garden — Fresh Livestock, Agriculture & Fisheries Products')]
class Home extends Component
{
    use HasQuickAddToCart;

    public string $productSector = '';

    public string $productSearch = '';

    public function resetProductFilters(): void
    {
        $this->reset('productSector', 'productSearch');
    }

    private function baseProductQuery(): Builder
    {
        return Product::query()
            ->with([
                'category.sector',
                'images' => fn ($query) => $query->orderBy('sort_order'),
                'variants' => fn ($query) => $query->saved()->where('is_active', true)->orderBy('price'),
                'variants.unit',
                'variants.pricePeriods',
                'variants.warehouseStocks.warehouse',
            ])
            ->withSum(['orderItems as total_sold' => fn ($query) => $query->whereHas(
                'order',
                fn ($query) => $query->where('status', '!=', OrderStatus::Cancelled),
            )], 'quantity')
            ->saved()
            ->where('is_active', true)
            ->whereHas('variants', fn (Builder $query) => $query->saved()->where('is_active', true))
            ->whereHas('category', fn ($query) => $query->saved()->where('is_active', true))
            ->whereHas('category.sector', fn ($query) => $query->saved()->where('is_active', true));
    }

    public function render(): View
    {
        $newestProductDays = ProductDisplaySetting::current()->new_badge_days;

        $sectors = Sector::query()
            ->with(['categories' => fn ($query) => $query->saved()->where('is_active', true)->orderBy('sort_order')])
            ->saved()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $newestProducts = $this->baseProductQuery()
            ->availableSince(now()->subDays($newestProductDays))
            ->orderByRaw('COALESCE(activated_at, created_at) DESC')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take(8)
            ->get();

        $bestSellerProducts = $this->baseProductQuery()
            ->orderByDesc('total_sold')
            ->take(20)
            ->get()
            ->filter(fn ($product) => ($product->total_sold ?? 0) > 0)
            ->take(8)
            ->values();

        $filteredBestSellers = $bestSellerProducts
            ->when($this->productSector !== '', fn ($products) => $products->filter(
                fn (Product $product): bool => $product->category->sector->slug === $this->productSector,
            ))
            ->when(trim($this->productSearch) !== '', fn ($products) => $products->filter(
                fn (Product $product): bool => str_contains(mb_strtolower($product->name), mb_strtolower(trim($this->productSearch))),
            ))
            ->values();

        $profile = CompanyProfile::current();

        return view('livewire.public.home', [
            'sectors' => $sectors,
            'newestProducts' => $newestProducts,
            'bestSellerProducts' => $filteredBestSellers,
            'hasBestSellers' => $bestSellerProducts->isNotEmpty(),
            'profile' => $profile,
        ]);
    }
}
