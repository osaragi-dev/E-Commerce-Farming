<?php

namespace App\Filament\Resources\ProductVariants\Schemas;

use App\Filament\Forms\Components\QuantityInput;
use App\Filament\Forms\Components\RupiahInput;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductVariantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn () => Product::query()->saved()->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                TextInput::make('name')
                    ->label('Variant')
                    ->required()
                    ->maxLength(100)
                    ->helperText('Contoh: Grade A, Size B, 250ml.'),
                QuantityInput::make('price_quantity')->required()->minValue(0.001)->default(1),
                RupiahInput::make('price')
                    ->required()
                    ->minValue(0),
                QuantityInput::make('stock_available')->label('Available')

                    ->required()
                    ->default(0)
                    ->minValue(0),
                QuantityInput::make('low_stock_threshold')->label('Min. Stock')

                    ->required()
                    ->default(0)
                    ->minValue(0)
                    ->helperText('Threshold before status changes to Critical.'),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
