<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Stocks\StockResource;
use App\Models\ProductVariant;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

class LowStockWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 50;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.low-stock-widget';

    public static function canView(): bool
    {
        return StockResource::canViewAny();
    }

    /** @return array{variants: Collection<int, ProductVariant>, total: int, url: string} */
    protected function getViewData(): array
    {
        $query = ProductVariant::query()->inventoryVisible()->lowStock();

        return [
            'variants' => (clone $query)
                ->with(['product', 'unit'])
                ->orderBy('stock_available')
                ->orderByDesc('low_stock_threshold')
                ->limit(5)
                ->get(),
            'total' => $query->count(),
            'url' => StockResource::getUrl('low-stock'),
        ];
    }
}
