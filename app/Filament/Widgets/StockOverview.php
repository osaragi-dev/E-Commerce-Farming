<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Stocks\StockResource;
use App\Models\WarehouseStock;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class StockOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 40;

    public static function canView(): bool
    {
        return StockResource::canViewAny();
    }

    protected function getStats(): array
    {
        $query = WarehouseStock::query()
            ->whereHas('warehouse', fn (Builder $warehouseQuery): Builder => $warehouseQuery
                ->saved()
                ->whereIn('code', StockResource::WAREHOUSE_CODES))
            ->whereHas('productVariant', fn (Builder $variantQuery): Builder => $variantQuery->inventoryVisible());

        return [
            Stat::make('Total Variants', (clone $query)->distinct('product_variant_id')->count('product_variant_id'))
                ->icon('heroicon-o-cube')
                ->color('info')
                ->extraAttributes(['class' => 'stock-overview-stat stock-overview-stat--info'])
                ->url(StockResource::getUrl('status', ['status' => 'all'])),
            Stat::make('Available', (clone $query)->availableStock()->count())
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->extraAttributes(['class' => 'stock-overview-stat stock-overview-stat--success'])
                ->url(StockResource::getUrl('status', ['status' => 'available'])),
            Stat::make('Low Stock', (clone $query)->lowStock()->count())
                ->color('warning')
                ->icon('heroicon-o-exclamation-triangle')
                ->extraAttributes(['class' => 'stock-overview-stat stock-overview-stat--warning'])
                ->url(StockResource::getUrl('low-stock')),
            Stat::make('Out of Stock', (clone $query)->outOfStock()->count())
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->extraAttributes(['class' => 'stock-overview-stat stock-overview-stat--danger'])
                ->url(StockResource::getUrl('status', ['status' => 'out-of-stock'])),
        ];
    }
}
