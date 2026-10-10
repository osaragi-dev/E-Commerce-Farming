<?php

namespace App\Filament\Resources\ProductDisplaySetting;

use App\Filament\Resources\ProductDisplaySetting\Pages\EditProductDisplaySetting;
use App\Filament\Resources\ProductDisplaySetting\Schemas\ProductDisplaySettingForm;
use App\Models\ProductDisplaySetting as ProductDisplaySettingModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ProductDisplaySettingResource extends Resource
{
    protected static ?string $model = ProductDisplaySettingModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 32;

    protected static ?string $navigationLabel = 'Product Display';

    protected static ?string $modelLabel = 'Product Display Setting';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ProductDisplaySettingForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => EditProductDisplaySetting::route('/{record}/edit'),
        ];
    }

    public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        if (! array_key_exists('record', $parameters)) {
            $parameters['record'] = ProductDisplaySettingModel::current()->getKey();
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }
}
