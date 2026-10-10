<?php

namespace App\Filament\Resources\Stocks\Tables;

use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\Stocks\StockResource;
use App\Filament\Tables\ApplicationTable;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Support\Quantity;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StocksTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'warehouse',
                'productVariant.product',
                'productVariant.unit',
            ]))
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('Warehouse')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse.code')
                    ->label('Warehouse Code')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('productVariant.product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('productVariant.name')
                    ->label('Variant')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('productVariant.unit.name')
                    ->label('Unit')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('productVariant.initial_stock')->formatStateUsing(fn ($state): string => Quantity::format($state))
                    ->label('Initial Stock')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stock_available')->formatStateUsing(fn ($state): string => Quantity::format($state))
                    ->label('Available')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('stock_reserved')->formatStateUsing(fn ($state): string => Quantity::format($state))
                    ->label('Reserved')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('stock_in_transit')->formatStateUsing(fn ($state): string => Quantity::format($state))
                    ->label('In Transit')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('stock_out')->formatStateUsing(fn ($state): string => Quantity::format($state))
                    ->label('Out')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('productVariant.low_stock_threshold')->formatStateUsing(fn ($state): string => Quantity::format($state))
                    ->label('Min. Stock')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('stockStatus')
                    ->label('Status')
                    ->badge()
                    ->toggleable()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $stocksTable = (new WarehouseStock)->getTable();
                        $variantsTable = (new ProductVariant)->getTable();
                        $sortDirection = $direction === 'desc' ? 'DESC' : 'ASC';

                        return $query
                            ->orderByRaw("CASE WHEN {$stocksTable}.stock_available <= 0 THEN 0 WHEN {$stocksTable}.stock_available <= (SELECT {$variantsTable}.low_stock_threshold FROM {$variantsTable} WHERE {$variantsTable}.id = {$stocksTable}.product_variant_id) THEN 1 ELSE 2 END {$sortDirection}")
                            ->orderBy("{$stocksTable}.stock_available", $direction)
                            ->orderBy("{$stocksTable}.id");
                    })
                    ->color(fn (string $state, WarehouseStock $record): string => $record->is_low_stock
                        ? 'warning'
                        : ($state === 'Out of Stock' ? 'danger' : 'success'))
                    ->icon(fn (WarehouseStock $record): ?string => $record->is_low_stock ? 'heroicon-o-exclamation-triangle' : null)
                    ->iconColor('warning')
                    ->tooltip(fn (WarehouseStock $record): ?string => $record->is_low_stock
                        ? "Stock is at or below the minimum ({$record->productVariant?->low_stock_threshold})"
                        : null),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->options(fn () => Product::query()->saved()->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $productId): Builder => $query->whereHas(
                            'productVariant',
                            fn (Builder $variantQuery): Builder => $variantQuery->where('product_id', $productId),
                        ),
                    )),
                Filter::make('low_stock')
                    ->label('Low/Out of Stock')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $query): Builder => $query
                            ->where('stock_available', '<=', 0)
                            ->orWhere(fn (Builder $query): Builder => $query->lowStock()),
                    )),
            ])
            // TCS revision (20 Aug): admin hanya boleh lihat & export
            // Stocks dari sini — perubahan angka Available tetap lewat
            // Stock Adjustments saja, biar semuanya tercatat di audit
            // trail. Tombol Edit sengaja dihapus dari sini.
            ->recordActions([])
            ->toolbarActions([
                TableExportActions::group(StockResource::class, exportPermission: 'Export:Stock'),
                BulkActionGroup::make([
                    ...TableExportActions::selected(StockResource::class, exportPermission: 'Export:Stock'),
                ])
                    ->label('Bulk Actions')
                    ->icon('heroicon-o-squares-plus')
                    ->color('gray')
                    ->button(),
            ])
            ->defaultSort('stockStatus', 'asc');

        return ApplicationTable::configureColumnManager($table);
    }
}
