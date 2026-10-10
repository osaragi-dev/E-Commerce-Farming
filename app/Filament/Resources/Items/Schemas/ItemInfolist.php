<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Filament\Support\Rupiah;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Item')->schema([
                TextEntry::make('code')->label('Item Code'),
                TextEntry::make('sku'),
                TextEntry::make('name'),
                TextEntry::make('item_type')->badge(),
                TextEntry::make('unit.code')->label('Base Unit'),
                TextEntry::make('initialWarehouse.name')->label('Initial Stock Location'),
                TextEntry::make('initial_stock')->numeric(decimalPlaces: 3),
                TextEntry::make('min_stock')->label('Min Stock')->numeric(decimalPlaces: 3),
                TextEntry::make('avg_purchase_price')
                    ->label('Avg Purchase Price')
                    ->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state)),
                TextEntry::make('purchasable')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                TextEntry::make('shelf_life_days')->label('Shelf Life (Days)'),
                IconEntry::make('is_active')->boolean(),
                ImageEntry::make('photo_path')->disk('public'),
                TextEntry::make('notes')->columnSpanFull(),
            ])->columns(2),
            Section::make('Audit')->schema([
                TextEntry::make('record_status')->badge(),
                TextEntry::make('createdBy.name')->label('Created By'),
                TextEntry::make('updatedBy.name')->label('Updated By'),
                TextEntry::make('deletedBy.name')->label('Deleted By'),
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('updated_at')->dateTime(),
                TextEntry::make('deleted_at')->dateTime(),
            ])->columns(3)->collapsed(),
        ]);
    }
}
