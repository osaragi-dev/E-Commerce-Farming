<?php

namespace App\Filament\Resources\ShippingSetting\Pages;

use App\Filament\Resources\ShippingSetting\ShippingSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditShippingSetting extends EditRecord
{
    protected static string $resource = ShippingSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index', ['record' => $this->record]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }
}
