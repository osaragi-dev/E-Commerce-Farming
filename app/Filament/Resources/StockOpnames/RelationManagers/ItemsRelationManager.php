<?php

namespace App\Filament\Resources\StockOpnames\RelationManagers;

use App\Filament\Resources\StockOpnames\Pages\ViewStockOpname;
use App\Models\StockOpnameItem;
use App\Support\Quantity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewStockOpname::class;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'session',
                'productVariant.product',
                'productVariant.unit',
            ]))
            ->columns([
                TextColumn::make('item')
                    ->label('Item')
                    ->state(fn (StockOpnameItem $record): string => sprintf(
                        '%s - %s',
                        $record->productVariant?->product?->name ?? 'Unknown Product',
                        $record->productVariant?->name ?? 'Unknown Variant',
                    ))
                    ->searchable(['productVariant.name', 'productVariant.product.name'])
                    ->sortable(['productVariant.name']),
                TextColumn::make('system_qty')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('System Qty')->alignEnd()->sortable(),
                TextColumn::make('physical_qty')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Physical Qty')->alignEnd()->placeholder('-')->sortable(),
                TextColumn::make('variance_qty')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Variance')->alignEnd()->placeholder('-')->sortable(),
                TextColumn::make('unit')->label('Unit')->placeholder('-'),
                TextColumn::make('notes')->label('Notes')->placeholder('-')->wrap(),
            ])
            ->defaultSort('created_at')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
