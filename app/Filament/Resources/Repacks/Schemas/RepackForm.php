<?php

namespace App\Filament\Resources\Repacks\Schemas;

use App\Filament\Forms\Components\QuantityInput;
use App\Filament\Forms\Components\RupiahInput;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Repack;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\RepackProductCreator;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RepackForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Source')->columns(2)->columnSpanFull()->schema([
                Select::make('warehouse_id')->label('Location')
                    ->options(fn (): array => Warehouse::query()->saved()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?string => Warehouse::query()->saved()->where('code', 'WH-FARMING')->value('id'))
                    ->required()->searchable()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('from_product_variant_id', null)),
                Select::make('from_product_variant_id')->label('From Product')
                    ->options(fn (Get $get, ?Repack $record): array => self::sourceOptions($get('warehouse_id'), $record))
                    ->required()->searchable()->live(),
                Placeholder::make('from_unit_display')->label('From Unit')
                    ->content(fn (Get $get): string => self::unit($get('from_product_variant_id'))),
                QuantityInput::make('from_quantity')->label('From Quantity')->required()->minValue(0.001)
                    ->suffix(fn (Get $get): string => 'Available: '.Quantity::format(self::available($get('warehouse_id'), $get('from_product_variant_id'))))
                    ->maxValue(fn (Get $get): ?string => self::available($get('warehouse_id'), $get('from_product_variant_id'))),
            ]),
            Section::make('Target')->columns(2)->columnSpanFull()->schema([
                Select::make('to_product_variant_id')->label('To Product')
                    ->options(fn (): array => ProductVariant::query()->inventoryVisible()->with('product')->get()
                        ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->product->name.' - '.$variant->name])->all())
                    ->required()->searchable()->live()
                    ->createOptionForm(self::productFields())
                    ->createOptionAction(fn (Action $action, Get $get): Action => $action
                        ->label('Create variant')->modalHeading('Create Variant')
                        ->disabled(fn (): bool => blank($get('warehouse_id')))
                        ->authorize(fn (): bool => Gate::allows('create', ProductVariant::class)))
                    ->createOptionUsing(function (array $data, Get $get, Schema $schema): string {
                        try {
                            return app(RepackProductCreator::class)->create($data, $get('warehouse_id'))->id;
                        } catch (ValidationException $exception) {
                            throw ValidationException::withMessages(collect($exception->errors())
                                ->mapWithKeys(fn (array $messages, string $key): array => [$schema->getStatePath().'.'.$key => $messages])->all());
                        }
                    }),
                Placeholder::make('to_unit_display')->label('To Unit')
                    ->content(fn (Get $get): string => self::unit($get('to_product_variant_id'))),
                QuantityInput::make('to_quantity')->label('To Quantity')->required()->minValue(0.001),
            ]),
            Textarea::make('notes')->label('Notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function unit(?string $id): string
    {
        return $id ? ProductVariant::with('unit')->find($id)?->unit?->name ?? '-' : '-';
    }

    public static function available(?string $warehouseId, ?string $variantId): ?string
    {
        $warehouse = $warehouseId ? Warehouse::query()->saved()->where('is_active', true)->find($warehouseId) : null;
        $variant = $variantId ? ProductVariant::query()->inventoryVisible()->with('warehouseStocks')->find($variantId) : null;

        return $warehouse && $variant ? Repack::availableAt($variant, $warehouse) : null;
    }

    /** @return array<string, string> */
    public static function sourceOptions(?string $warehouseId, ?Repack $record = null): array
    {
        $warehouse = $warehouseId ? Warehouse::query()->saved()->where('is_active', true)->find($warehouseId) : null;
        if (! $warehouse) {
            return [];
        }

        return ProductVariant::query()->eligibleForStocktake($warehouse->id)->with(['product', 'warehouseStocks'])->get()
            ->filter(fn (ProductVariant $variant): bool => bccomp(Repack::availableAt($variant, $warehouse), '0', 3) > 0 || $record?->from_product_variant_id === $variant->id)
            ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->product->name.' - '.$variant->name])->all()
            + ($record && $record->warehouse_id === $warehouseId && $record->fromProductVariant?->product
                ? [$record->from_product_variant_id => $record->fromProductVariant->product->name.' - '.$record->fromProductVariant->name] : []);
    }

    /** @return array<Component> */
    private static function productFields(): array
    {
        return [
            Select::make('product_id')->label('Product')
                ->options(fn (): array => Product::query()->saved()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->required(),
            TextInput::make('name')->label('Variant')->required()->maxLength(100),
            Select::make('unit_id')->label('Unit')
                ->options(fn (): array => Unit::query()->saved()->where('is_active', true)->pluck('code', 'id')->all())->required()->searchable(),
            QuantityInput::make('price_quantity')->label('Price Quantity')->required()->default(1)->minValue(0.001),
            RupiahInput::make('price')->required()->minValue(0),
            QuantityInput::make('low_stock_threshold')->label('Min. Stock')->required()->default(0)->minValue(0),
        ];
    }
}
