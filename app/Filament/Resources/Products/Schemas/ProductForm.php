<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Forms\Components\QuantityInput;
use App\Filament\Forms\Components\RupiahInput;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('category_id')
                    ->label('Category')
                    ->options(fn () => Category::query()->saved()->where('is_active', true)->with('sector')->get()
                        ->mapWithKeys(fn (Category $category) => [
                            $category->id => "{$category->sector?->name} - {$category->name}",
                        ]))
                    ->searchable()
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->extraInputAttributes([
                        'x-on:input' => <<<'JS'
                            let slug = $el.value
                                .toString()
                                .toLowerCase()
                                .trim()
                                .replace(/[^a-z0-9]+/g, '-')
                                .replace(/^-+|-+$/g, '');
                            let container = $el.closest('form, [wire\\:id]');
                            let slugInput = container?.querySelector('input[wire\\:model*=slug]');
                            if (slugInput) {
                                slugInput.value = slug;
                                slugInput.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                        JS,
                    ]),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(280)
                    ->helperText('Unique among saved products. Drafts may reuse a slug.'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Textarea::make('description')
                    ->rows(4)
                    ->columnSpanFull(),

                // TCS revision: Variant bukan menu terpisah, dikelola
                // langsung di sini lewat form Product (Repeater).
                //
                // Bug fix (24 Aug 2026): tanpa orderBy eksplisit, urutan
                // Variant yang ditampilkan bisa "geser" sendiri kalau ada
                // baris yang di-UPDATE dari luar (misal lewat Stock
                // Adjustment yang cuma ubah stock_available) — PostgreSQL
                // tidak menjamin urutan baris tanpa ORDER BY, dan MVCC-nya
                // bisa mindahin versi baris fisik pas di-update. Dikunci
                // pakai created_at biar urutan tampil selalu stabil,
                // tidak terpengaruh aktivitas Stock Adjustment sama sekali.
                Repeater::make('variants')
                    ->relationship(modifyQueryUsing: fn (Builder $query) => $query->orderBy('created_at'))
                    ->label('Variants')
                    ->schema([
                        // Diperlukan supaya $get('id') di bawah bisa
                        // membedakan item Variant yang sudah tersimpan
                        // (punya id) dari item baru yang baru ditambahkan
                        // di form (belum punya id).
                        Hidden::make('id'),
                        Tabs::make('variant_tabs')
                            ->tabs([
                                Tab::make('Variant Details')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Variant')
                                            ->required()
                                            ->maxLength(100)
                                            ->helperText('Contoh: Grade A, Size B, 250ml.'),
                                        Select::make('unit_id')
                                            ->label('Unit')
                                            ->options(fn () => Unit::query()->saved()->where('is_active', true)->pluck('code', 'id'))
                                            ->searchable()
                                            ->preload()
                                            ->required(),
                                        QuantityInput::make('price_quantity')
                                            ->label('Price Quantity')->required()->default(1)->minValue(0.001)
                                            ->helperText('The quantity covered by the price, in the selected unit.'),
                                        RupiahInput::make('price')
                                            ->required()
                                            ->minValue(0),
                                        QuantityInput::make('initial_stock')
                                            ->label('Initial Stock')

                                            ->required()
                                            ->default(0)
                                            ->minValue(0)
                                            ->disabled(fn (Get $get): bool => filled($get('id')))
                                            ->dehydrated()
                                            ->helperText(fn (Get $get): ?string => filled($get('id'))
                                                ? 'Initial stock terkunci setelah variant tersimpan.'
                                                : null),
                                        Select::make('initial_warehouse_id')
                                            ->label('Initial Stock Location')
                                            ->options(fn () => Warehouse::query()->saved()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                            ->searchable()
                                            ->preload()
                                            ->required(fn (Get $get): bool => blank($get('id')))
                                            ->disabled(fn (Get $get): bool => filled($get('id')))
                                            ->helperText('Initial stock is added to this warehouse. Use Stock Movements to transfer it later.'),
                                        QuantityInput::make('low_stock_threshold')
                                            ->label('Min. Stock')

                                            ->required()
                                            ->default(0)
                                            ->minValue(0),
                                        Toggle::make('is_active')
                                            ->label('Active')
                                            ->default(true),
                                    ])
                                    ->columns(3),
                                Tab::make('Promotional Price')
                                    ->schema([
                                        Repeater::make('pricePeriods')
                                            ->relationship()
                                            ->label('Promotional Prices')
                                            ->schema([
                                                RupiahInput::make('price')
                                                    ->label('Price')
                                                    ->required()
                                                    ->minValue(0),
                                                DatePicker::make('effective_from')
                                                    ->label('Effective From')
                                                    ->required(),
                                                DatePicker::make('effective_until')
                                                    ->label('Effective Until')
                                                    ->afterOrEqual('effective_from'),
                                            ])
                                            ->columns(3)
                                            ->defaultItems(0)
                                            ->addActionLabel('Add Promotional Price')
                                            ->columnSpanFull()
                                            ->helperText('After the period ends, the variant returns to its default price.'),
                                    ]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->minItems(1)
                    ->addActionLabel('Add Variant')
                    ->reorderable(false)
                    ->columnSpanFull()
                    ->helperText('Minimal 1 variant. Foto produk dikelola di halaman detail (View) setelah produk disimpan.'),
            ]);
    }
}
