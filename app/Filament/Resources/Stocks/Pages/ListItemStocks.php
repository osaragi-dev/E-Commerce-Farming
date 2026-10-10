<?php

namespace App\Filament\Resources\Stocks\Pages;

use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\Stocks\StockResource;
use App\Filament\Tables\ApplicationTable;
use App\Models\ItemWarehouseStock;
use App\Support\Quantity;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListItemStocks extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = StockResource::class;

    protected string $view = 'filament.resources.stocks.pages.stock-warehouses';

    public function getTitle(): string
    {
        return 'Item Stocks';
    }

    public function table(Table $table): Table
    {
        return ApplicationTable::configureColumnManager($table
            ->query(ItemWarehouseStock::query()
                ->whereHas('item', fn (Builder $query): Builder => $query->saved()->where('is_active', true))
                ->with(['item.unit', 'item', 'warehouse']))
            ->columns([
                TextColumn::make('warehouse.name')->label('Warehouse')->placeholder('-')->searchable()->sortable(),
                TextColumn::make('item.name')->label('Item')->searchable()->sortable(),
                TextColumn::make('item.unit.code')->label('Unit')->placeholder('-')->sortable(),
                TextColumn::make('stock_available')
                    ->label('Available')
                    ->formatStateUsing(fn (mixed $state): string => Quantity::format($state))
                    ->sortable(),
                TextColumn::make('stock_reserved')
                    ->label('Reserved')
                    ->formatStateUsing(fn (mixed $state): string => Quantity::format($state))
                    ->sortable(),
                TextColumn::make('stock_in_transit')
                    ->label('In Transit')
                    ->formatStateUsing(fn (mixed $state): string => Quantity::format($state))
                    ->sortable(),
                TextColumn::make('stock_out')
                    ->label('Out')
                    ->formatStateUsing(fn (mixed $state): string => Quantity::format($state))
                    ->sortable(),
                TextColumn::make('item.min_stock')
                    ->label('Min. Stock')
                    ->formatStateUsing(fn (mixed $state): string => Quantity::format($state))
                    ->sortable(),
                TextColumn::make('stock_status')
                    ->label('Status')
                    ->badge()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $sortDirection = $direction === 'desc' ? 'DESC' : 'ASC';

                        return $query->orderByRaw(
                            "CASE WHEN item_warehouse_stocks.stock_available <= 0 THEN 0 WHEN item_warehouse_stocks.stock_available <= (SELECT items.min_stock FROM items WHERE items.id = item_warehouse_stocks.item_id) THEN 1 ELSE 2 END {$sortDirection}",
                        )->orderBy('item_warehouse_stocks.stock_available', $direction);
                    })
                    ->color(fn (ItemWarehouseStock $record): string => match ($record->stock_status) {
                        'Out of Stock' => 'danger',
                        'Low Stock' => 'warning',
                        default => 'success',
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([
                TableExportActions::group(StockResource::class, StockResource::itemExportDefinition(), ItemWarehouseStock::class, exportPermission: 'Export:Stock'),
                BulkActionGroup::make([
                    ...TableExportActions::selected(StockResource::class, StockResource::itemExportDefinition(), ItemWarehouseStock::class, exportPermission: 'Export:Stock'),
                ])
                    ->label('Bulk Actions')
                    ->icon('heroicon-o-squares-plus')
                    ->color('gray')
                    ->button(),
            ])
            ->defaultSort('item.name'));
    }
}
