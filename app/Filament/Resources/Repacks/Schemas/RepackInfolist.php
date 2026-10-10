<?php

namespace App\Filament\Resources\Repacks\Schemas;

use App\Models\Repack;
use App\Models\StockMutation;
use App\Support\Quantity;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RepackInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('repack_number')->label('Convert Number')->placeholder('-'),
            TextEntry::make('display_status')->label('Status')->badge(),
            TextEntry::make('warehouse.name')->label('Location'),
            Section::make('Source')->schema([
                TextEntry::make('fromProductVariant.product.name')->label('From Product'),
                TextEntry::make('fromProductVariant.name')->label('From Variant'),
                TextEntry::make('from_quantity')->label('From Qty')->formatStateUsing(fn ($state): string => Quantity::format($state)),
                TextEntry::make('from_unit')->label('From Unit'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Target')->schema([
                TextEntry::make('toProductVariant.product.name')->label('To Product'),
                TextEntry::make('toProductVariant.name')->label('To Variant'),
                TextEntry::make('to_quantity')->label('To Qty')->formatStateUsing(fn ($state): string => Quantity::format($state)),
                TextEntry::make('to_unit')->label('To Unit'),
            ])->columns(2)->columnSpanFull(),
            TextEntry::make('notes')->label('Notes')->placeholder('-')->columnSpanFull(),
            TextEntry::make('created_at')->label('Created At')->dateTime(),
            TextEntry::make('createdBy.name')->label('Created By'),
            TextEntry::make('updated_at')->label('Updated At')->dateTime()->placeholder('-'),
            TextEntry::make('updatedBy.name')->label('Updated By')->placeholder('-'),
            TextEntry::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-'),
            TextEntry::make('deletedBy.name')->label('Deleted By')->placeholder('-'),
            TextEntry::make('cancelled_at')->label('Cancelled At')->dateTime()->placeholder('-'),
            TextEntry::make('cancelledBy.name')->label('Cancelled By')->placeholder('-'),
            Section::make('Stock History')->schema([
                TextEntry::make('stockMutations')->label('Changes')
                    ->state(fn (Repack $record): array => $record->stockMutations->map(
                        fn (StockMutation $mutation): string => ($mutation->productVariant?->product?->name ?? '-').' - '.$mutation->productVariant?->name
                            .': '.Quantity::format($mutation->stock_before).' → '.Quantity::format($mutation->stock_after)
                    )->all())->listWithLineBreaks()->placeholder('No stock changes for this draft.'),
            ])->columnSpanFull(),
        ])->columns(2);
    }
}
