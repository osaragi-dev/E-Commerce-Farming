<?php

namespace App\Filament\Resources\StockMutations\Tables;

use App\Enums\StockMutationSource;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\StockMutations\StockMutationResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMutation;
use App\Support\Quantity;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMutationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('Warehouse')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
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
                TextColumn::make('source')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('repack.repack_number')->label('Convert Number')->placeholder('-')
                    ->url(fn (StockMutation $record): string => StockMutationResource::activityUrl($record))
                    ->toggleable(),
                TextColumn::make('stock_before')->formatStateUsing(fn ($state): string => Quantity::format($state))
                    ->label('Before')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('quantity_change')
                    ->label('Change')
                    ->sortable()
                    ->toggleable()
                    ->color(fn (string $state): string => $state >= 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (string $state): string => ($state >= 0 ? '+' : '').Quantity::format($state)),
                TextColumn::make('stock_after')->formatStateUsing(fn ($state): string => Quantity::format($state))
                    ->label('After')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('recordedBy.name')
                    ->label('Recorded By')
                    ->placeholder('System')
                    ->sortable()
                    ->toggleable(),
                // TCS revision (22/8): dipindah ke akhir, setelah
                // "Recorded By" — sebelumnya ini kolom pertama.
                TextColumn::make('created_at')
                    ->label('Recorded At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name')
                    ->label('Warehouse'),
                SelectFilter::make('source')
                    ->options([
                        StockMutationSource::Order->value => StockMutationSource::Order->getLabel(),
                        StockMutationSource::CancelOrder->value => StockMutationSource::CancelOrder->getLabel(),
                        StockMutationSource::ManualAdjustment->value => StockMutationSource::ManualAdjustment->getLabel(),
                        StockMutationSource::InitialStock->value => StockMutationSource::InitialStock->getLabel(),
                        StockMutationSource::Transfer->value => StockMutationSource::Transfer->getLabel(),
                        StockMutationSource::CancelTransfer->value => StockMutationSource::CancelTransfer->getLabel(),
                        StockMutationSource::Stocktake->value => StockMutationSource::Stocktake->getLabel(),
                        StockMutationSource::Repack->value => StockMutationSource::Repack->getLabel(),
                        StockMutationSource::CancelRepack->value => StockMutationSource::CancelRepack->getLabel(),
                        StockMutationSource::StockWaste->value => StockMutationSource::StockWaste->getLabel(),
                        StockMutationSource::CancelStockWaste->value => StockMutationSource::CancelStockWaste->getLabel(),
                    ]),
                // TCS revision (28/8): tambah filter by Product (terpisah
                // dari filter Product Variant di bawah).
                SelectFilter::make('product_id')
                    ->label('Product')
                    ->options(fn () => Product::query()->saved()->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'],
                        fn (Builder $query, $productId): Builder => $query->whereHas(
                            'productVariant',
                            fn (Builder $query) => $query->where('product_id', $productId)
                        )
                    )),
                SelectFilter::make('product_variant_id')
                    ->label('Product Variant')
                    // TCS revision (28/8): format "Produk - Variant" biar
                    // jelas variant itu punya produk yang mana (sebelumnya
                    // cuma nama variant doang, ambigu kalau ada nama sama).
                    ->options(fn () => ProductVariant::query()->saved()
                        ->with('product')
                        ->get()
                        ->mapWithKeys(fn (ProductVariant $variant): array => [
                            $variant->id => ($variant->product?->name ?? 'Unknown Product')." - {$variant->name}",
                        ])),
                DateRangeFilter::make('created_at')
                    ->label('Date')
                    ->displayFormat('d/m/Y')
                    ->startPlaceholder('dd/mm/yyyy')
                    ->endPlaceholder('dd/mm/yyyy')
                    ->firstDayOfWeek(1)
                    ->dualCalendar(false)
                    ->inline(true)
                    ->locale('en')
                    ->timezone('Asia/Jakarta'),
            ])
            ->filtersFormWidth(Width::Large)
            // TCS revision (22/8): default Filament pecah kata pencarian
            // jadi token per kata, terus nyari tiap token itu di SEMUA
            // kolom searchable secara terpisah — bikin "Grade A" ikut
            // nampilin "Grade B" (gara-gara nama Product-nya kebetulan
            // ada huruf "a"). Dimatikan biar cari FRASA UTUH.
            ->splitSearchTerms(false)
            ->recordActions([])
            ->recordUrl(fn (StockMutation $record): string => StockMutationResource::activityUrl($record))
            ->selectable(false)
            ->toolbarActions([
                TableExportActions::group(StockMutationResource::class),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
