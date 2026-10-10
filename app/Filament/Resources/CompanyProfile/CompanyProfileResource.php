<?php

namespace App\Filament\Resources\CompanyProfile;

use App\Filament\Resources\CompanyProfile\Pages\EditCompanyProfile;
use App\Filament\Resources\CompanyProfile\RelationManagers\GalleryRelationManager;
use App\Filament\Resources\CompanyProfile\Schemas\CompanyProfileForm;
use App\Models\CompanyProfile as CompanyProfileModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class CompanyProfileResource extends Resource
{
    protected static ?string $model = CompanyProfileModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Company Profile';

    protected static ?string $modelLabel = 'Company Profile';

    protected static ?string $recordTitleAttribute = 'company_name';

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
        return CompanyProfileForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => EditCompanyProfile::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            GalleryRelationManager::class,
        ];
    }

    public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        if (! array_key_exists('record', $parameters)) {
            $parameters['record'] = CompanyProfileModel::current()->getKey();
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }
}
