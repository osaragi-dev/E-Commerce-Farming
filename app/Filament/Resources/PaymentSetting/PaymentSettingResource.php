<?php

namespace App\Filament\Resources\PaymentSetting;

use App\Filament\Resources\PaymentSetting\Pages\EditPaymentSetting;
use App\Filament\Resources\PaymentSetting\Schemas\PaymentSettingForm;
use App\Models\PaymentSetting as PaymentSettingModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class PaymentSettingResource extends Resource
{
    protected static ?string $model = PaymentSettingModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 33;

    protected static ?string $navigationLabel = 'Payment Settings';

    protected static ?string $modelLabel = 'Payment Setting';

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
        return PaymentSettingForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => EditPaymentSetting::route('/{record}/edit'),
        ];
    }

    public static function getUrl(
        ?string $name = null,
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null,
    ): string {
        if (! array_key_exists('record', $parameters)) {
            $parameters['record'] = PaymentSettingModel::current()->getKey();
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }
}
