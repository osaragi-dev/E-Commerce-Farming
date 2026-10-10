<?php

namespace App\Filament\Resources\ShippingSetting;

use App\Filament\Resources\ShippingSetting\Pages\EditShippingSetting;
use App\Filament\Resources\ShippingSetting\Schemas\ShippingSettingForm;
use App\Models\ShippingSetting as ShippingSettingModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ShippingSettingResource extends Resource
{
    protected static ?string $model = ShippingSettingModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 31;

    protected static ?string $navigationLabel = 'Shipping';

    protected static ?string $modelLabel = 'Shipping Setting';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

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
        return ShippingSettingForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => EditShippingSetting::route('/{record}/edit'),
        ];
    }

    public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        if (! array_key_exists('record', $parameters)) {
            $parameters['record'] = ShippingSettingModel::current()->getKey();
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }
}
