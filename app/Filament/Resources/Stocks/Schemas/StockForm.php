<?php

namespace App\Filament\Resources\Stocks\Schemas;

use App\Filament\Forms\Components\QuantityInput;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Schema;

/**
 * Cuma 'stock_available' yang bisa diedit di sini (Item 1: update cepat).
 * Reserved digerakkan oleh Order System; Out oleh Order System dan Convert.
 * Keduanya cuma
 * ditampilkan (read only), tidak bisa diketik manual.
 */
class StockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('product_name')
                    ->label('Product')
                    ->content(fn ($record) => $record?->productVariant?->product?->name.' - '.$record?->productVariant?->name),
                QuantityInput::make('stock_available')
                    ->label('Available')

                    ->required()
                    ->minValue(0)
                    ->helperText('Perubahan otomatis tercatat di Stock Mutations.'),
                Placeholder::make('stock_reserved')
                    ->label('Reserved (read only)')
                    ->content(fn ($record) => $record?->stock_reserved ?? 0),
                Placeholder::make('stock_in_transit')
                    ->label('In Transit (read only)')
                    ->content(fn ($record) => $record?->stock_in_transit ?? 0),
                Placeholder::make('stock_out')
                    ->label('Out (read only)')
                    ->content(fn ($record) => $record?->stock_out ?? 0),
            ])
            ->columns(2);
    }
}
