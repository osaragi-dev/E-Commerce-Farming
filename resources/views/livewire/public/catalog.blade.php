<div class="catalog-page">
    <section class="catalog-section">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">Catalog</span>
            </nav>

            <header class="commerce-heading catalog-heading">
                <p class="eyebrow">From our farm</p>
                <h1>All Products</h1>
                <p>Explore fresh choices from our livestock, agriculture, and fisheries.</p>
            </header>

            <div class="catalog-controls">
                <div class="catalog-sector-tabs" role="group" aria-label="Filter by sector">
                    <button type="button" wire:click="$set('sectorSlug', '')" class="sector-filter {{ $sectorSlug === '' ? 'is-active' : '' }}" aria-pressed="{{ $sectorSlug === '' ? 'true' : 'false' }}">
                        <span class="sector-filter-icon"><x-public.sector-icon class="size-8" /></span>
                        <span class="sector-filter-label">All Products</span>
                    </button>
                    @foreach ($sectors as $sector)
                        <button type="button" wire:key="sector-filter-{{ $sector->id }}" wire:click="$set('sectorSlug', '{{ $sector->slug }}')" class="sector-filter {{ $sectorSlug === $sector->slug ? 'is-active' : '' }}" aria-pressed="{{ $sectorSlug === $sector->slug ? 'true' : 'false' }}">
                            <span class="sector-filter-icon">
                                @if ($sector->image && \Illuminate\Support\Facades\Storage::disk('public')->exists($sector->image))
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($sector->image) }}" alt="" width="64" height="64" loading="lazy">
                                @else
                                    <x-public.sector-icon :slug="$sector->slug" class="size-8" />
                                @endif
                            </span>
                            <span class="sector-filter-label">{{ $sector->name }}</span>
                        </button>
                    @endforeach
                </div>

                @if ($categories->isNotEmpty())
                    <div class="category-filters flex flex-wrap items-center gap-2 mt-4" role="group" aria-label="Filter by category">
                        <button type="button" wire:click="clearCategories" class="pill-filter {{ empty($categorySlugs) ? 'is-active' : '' }}" aria-pressed="{{ empty($categorySlugs) ? 'true' : 'false' }}">All Categories</button>
                        @foreach ($categories as $category)
                            <button type="button" wire:key="category-filter-{{ $category->id }}" wire:click="toggleCategory('{{ $category->slug }}')" class="pill-filter {{ in_array($category->slug, $categorySlugs, true) ? 'is-active' : '' }}" aria-pressed="{{ in_array($category->slug, $categorySlugs, true) ? 'true' : 'false' }}">{{ $category->name }}</button>
                        @endforeach
                    </div>
                @endif

                <div class="catalog-search-row">
                    <div class="flex-1 min-w-0">
                        <label for="catalog-search" class="form-label">Search products</label>
                        <div class="search-shell mt-2">
                            <x-heroicon-o-magnifying-glass class="size-5 shrink-0" aria-hidden="true" />
                            <input id="catalog-search" type="search" wire:model.live.debounce.300ms="search" placeholder="What are you looking for?">
                        </div>
                    </div>
                    <div class="sm:w-48">
                        <label for="catalog-sort" class="form-label">Sort by</label>
                        <select id="catalog-sort" wire:model.live="sort" class="select-control w-full mt-2">
                            <option value="terbaru">Newest</option>
                            <option value="terlaris">Best Selling</option>
                            <option value="nama">Name A-Z</option>
                            <option value="harga_termurah">Lowest Price</option>
                            <option value="harga_termahal">Highest Price</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="catalog-result-bar flex flex-wrap items-center justify-between gap-3 mt-7">
                <p class="text-sm" style="color: var(--ink-soft)" role="status">
                    {{ $products->total() }} {{ $products->total() === 1 ? 'product' : 'products' }} found
                    @if (trim($search) !== '')
                        for "<strong>{{ $search }}</strong>"
                    @endif
                </p>
                <span wire:loading.delay wire:target="search,sort,sectorSlug,toggleCategory,clearCategories,gotoPage,nextPage,previousPage" class="text-sm" role="status">Updating products...</span>
            </div>

            <div wire:loading.class="opacity-50" wire:target="search,sort,sectorSlug,toggleCategory,clearCategories,gotoPage,nextPage,previousPage" class="transition-opacity">
                @if ($products->isEmpty())
                    <div class="empty-state mt-6">
                        <div class="empty-state-badge"><x-heroicon-o-magnifying-glass class="size-8" aria-hidden="true" /></div>
                        <h2>No products found</h2>
                        <p>Try another search or choose a different category.</p>
                        <a href="{{ route('catalog') }}" class="btn btn-ghost mt-5">Clear all filters</a>
                    </div>
                @else
                    <div class="product-grid mt-6" x-data="publicReveal">
                        @foreach ($products as $product)
                            <x-public.product-card :product="$product" wire:key="catalog-product-{{ $product->id }}" />
                        @endforeach
                    </div>

                    <div class="mt-10">
                        {{ $products->links() }}
                    </div>
                @endif
            </div>
        </div>
    </section>
</div>
