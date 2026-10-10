<x-filament-panels::page>
    <div class="stock-category-grid">
        <a class="stock-category-card" href="{{ \App\Filament\Resources\Stocks\StockResource::getUrl('items') }}">
            <span class="stock-category-card__icon">
                <x-filament::icon icon="heroicon-o-rectangle-stack" class="size-6" />
            </span>
            <span class="stock-category-card__content">
                <span class="stock-category-card__title">Items</span>
                <span class="stock-category-card__description">View initial stock and minimum stock for purchasing items.</span>
            </span>
            <x-filament::icon icon="heroicon-o-arrow-right" class="stock-category-card__arrow size-5" />
        </a>

        <a class="stock-category-card" href="{{ \App\Filament\Resources\Stocks\StockResource::getUrl('products') }}">
            <span class="stock-category-card__icon">
                <x-filament::icon icon="heroicon-o-cube" class="size-6" />
            </span>
            <span class="stock-category-card__content">
                <span class="stock-category-card__title">Products</span>
                <span class="stock-category-card__description">View product and variant stock by warehouse.</span>
            </span>
            <x-filament::icon icon="heroicon-o-arrow-right" class="stock-category-card__arrow size-5" />
        </a>
    </div>
</x-filament-panels::page>
