<?php

namespace App\Livewire\Public;

use App\Enums\OrderStatus;
use App\Livewire\Concerns\HasQuickAddToCart;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sector;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('All Products — Agung Farm & Garden')]
class Catalog extends Component
{
    use HasQuickAddToCart, WithPagination;

    private const PER_PAGE = 16;

    #[Url(as: 'sector', history: true)]
    public string $sectorSlug = '';

    #[Url(as: 'category', history: true)]
    public array $categorySlugs = [];

    #[Url(as: 'sort', history: true)]
    public string $sort = 'terbaru';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    /** Switching Sector clears the Category pills so they don't point at categories that no longer apply. */
    public function updatedSectorSlug(): void
    {
        $this->categorySlugs = [];
        $this->resetPage();
    }

    public function toggleCategory(string $slug): void
    {
        if (in_array($slug, $this->categorySlugs, true)) {
            $this->categorySlugs = array_values(array_diff($this->categorySlugs, [$slug]));
        } else {
            $this->categorySlugs[] = $slug;
        }

        $this->resetPage();
    }

    public function clearCategories(): void
    {
        $this->categorySlugs = [];
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $sectors = Sector::query()
            ->saved()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug']);

        $categories = Category::query()
            ->saved()
            ->where('is_active', true)
            ->when(
                $this->sectorSlug !== '',
                fn (Builder $query) => $query->whereHas(
                    'sector',
                    fn (Builder $query) => $query->where('slug', $this->sectorSlug),
                ),
            )
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'sector_id']);

        $query = Product::query()
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
            ->whereHas('category', fn (Builder $query) => $query->saved()->where('is_active', true))
            ->whereHas('category.sector', fn (Builder $query) => $query->saved()->where('is_active', true));

        if ($this->sectorSlug !== '') {
            $query->whereHas('category.sector', fn (Builder $query) => $query->where('slug', $this->sectorSlug));
        }

        if (! empty($this->categorySlugs)) {
            $query->whereHas('category', fn (Builder $query) => $query->whereIn('slug', $this->categorySlugs));
        }

        if (trim($this->search) !== '') {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($this->search)).'%']);
        }

        $query = match ($this->sort) {
            'terlaris' => $query
                ->orderByDesc('total_sold')
                ->orderBy('name'),
            'nama' => $query->orderBy('name'),
            'harga_termurah' => $query
                ->withMin(['variants as min_price' => fn ($query) => $query->saved()->where('is_active', true)], 'price')
                ->orderBy('min_price'),
            'harga_termahal' => $query
                ->withMax(['variants as max_price' => fn ($query) => $query->saved()->where('is_active', true)], 'price')
                ->orderByDesc('max_price'),
            default => $query->latest('created_at'),
        };

        $products = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('livewire.public.catalog', [
            'sectors' => $sectors,
            'categories' => $categories,
            'products' => $products,
        ]);
    }
}
