<?php

namespace App\Filament\Resources\ProductVariants\Schemas;

use App\Filament\Support\Rupiah;
use App\Support\Quantity;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductVariantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Variant')
                    ->schema([
                        TextEntry::make('product.name')->label('Product'),
                        TextEntry::make('name')->label('Variant'),
                        TextEntry::make('price_quantity')->label('Price Quantity')->formatStateUsing(fn ($state): string => Quantity::format($state)),
                        TextEntry::make('price')
                            ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                        TextEntry::make('stock_available')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Available'),
                        TextEntry::make('stock_reserved')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Reserved'),
                        TextEntry::make('stock_in_transit')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('In Transit'),
                        TextEntry::make('stock_out')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Out'),
                        TextEntry::make('low_stock_threshold')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Min. Stock'),
                        TextEntry::make('stockStatus')->label('Status Stok')->badge(),
                        IconEntry::make('is_active')->boolean(),
                    ])
                    ->columns(2),
                Section::make('Audit')
                    ->schema([
                        TextEntry::make('record_status')->badge(),
                        TextEntry::make('createdBy.name')->label('Created By'),
                        TextEntry::make('updatedBy.name')->label('Updated By'),
                        TextEntry::make('deletedBy.name')->label('Deleted By'),
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('updated_at')->dateTime(),
                        TextEntry::make('deleted_at')->dateTime(),
                    ])
                    ->columns(3)
                    ->collapsed(false),
            ]);
    }
}
