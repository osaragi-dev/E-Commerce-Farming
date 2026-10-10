<?php

namespace App\Filament\Resources\ProductVariants\Tables;

use App\Enums\RecordStatus;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Tables\ApplicationTable;
use App\Filament\Tables\Columns\RupiahColumn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Quantity;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductVariantsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Variant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('unit.name')
                    ->label('Unit')
                    ->sortable(),
                TextColumn::make('price_quantity')->label('Price Quantity')->formatStateUsing(fn ($state): string => Quantity::format($state)),
                RupiahColumn::make('price')
                    ->sortable(),
                TextColumn::make('stock_available')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Available')
                    ->sortable(),
                TextColumn::make('stock_reserved')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Reserved')
                    ->sortable(),
                TextColumn::make('stock_in_transit')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('In Transit')
                    ->sortable(),
                TextColumn::make('stock_out')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Out')
                    ->sortable(),
                TextColumn::make('stockStatus')
                    ->label('Status Stock')
                    ->badge()
                    ->color(fn (string $state, ProductVariant $record): string => $record->is_low_stock
                        ? 'warning'
                        : ($state === 'Out of Stock' ? 'danger' : 'success')),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->options(fn () => Product::query()->saved()->pluck('name', 'id')),
                Filter::make('low_stock')
                    ->label('Critical/Sold')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('stock_available', '<=', 'low_stock_threshold')),
                SelectFilter::make('record_status')
                    ->options([
                        RecordStatus::Draft->value => 'Draft',
                        RecordStatus::Saved->value => 'Saved',
                        RecordStatus::Deleted->value => 'Deleted',
                    ]),
                SelectFilter::make('is_active')
                    ->options([1 => 'Active', 0 => 'Inactive']),
                DateRangeFilter::make('created_at')->label('Created Date'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);

        return ApplicationTable::configure($table, ProductVariantResource::class);
    }
}
