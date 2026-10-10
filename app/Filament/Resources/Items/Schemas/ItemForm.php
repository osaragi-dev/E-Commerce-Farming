<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Enums\ItemType;
use App\Filament\Forms\Components\QuantityInput;
use App\Filament\Forms\Components\RupiahInput;
use App\Models\Unit;
use App\Models\Warehouse;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Item Code')
                    ->required()
                    ->maxLength(100)
                    ->helperText('Must be unique among saved items. Drafts may reuse a code.'),
                TextInput::make('sku')
                    ->required()
                    ->maxLength(100)
                    ->helperText('Must be unique among saved items. Drafts may reuse a SKU.'),
                TextInput::make('name')->required()->maxLength(255),
                Select::make('item_type')
                    ->options(ItemType::class)
                    ->required(),
                Select::make('unit_id')
                    ->label('Base Unit')
                    ->options(fn (): array => Unit::query()->saved()->where('is_active', true)->orderBy('name')->pluck('code', 'id')->all())
                    ->searchable()
                    ->required(),
                Select::make('initial_warehouse_id')
                    ->label('Initial Stock Location')
                    ->options(fn (): array => Warehouse::query()->saved()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(fn (Get $get): bool => (float) $get('initial_stock') > 0),
                QuantityInput::make('initial_stock')
                    ->required()
                    ->default(0)
                    ->minValue(0),
                QuantityInput::make('min_stock')
                    ->label('Min Stock')
                    ->required()
                    ->default(0)
                    ->minValue(0),
                RupiahInput::make('avg_purchase_price')
                    ->label('Avg Purchase Price')
                    ->required()
                    ->default(0)
                    ->minValue(0),
                Toggle::make('is_active')->label('Active')->default(true),
                Toggle::make('purchasable')->default(true),
                FileUpload::make('photo_path')
                    ->label('Upload Photo')
                    ->image()
                    ->maxSize(7168)
                    ->disk('public')
                    ->directory('items')
                    ->visibility('public')
                    ->helperText('Allowed: image files (JPEG, PNG, GIF, etc.). Max size: 7 MB.'),
                TextInput::make('shelf_life_days')
                    ->numeric()
                    ->integer()
                    ->minValue(0),
                Textarea::make('notes')->placeholder('Optional notes...')->rows(3)->columnSpanFull(),
            ])
            ->columns(2);
    }
}
