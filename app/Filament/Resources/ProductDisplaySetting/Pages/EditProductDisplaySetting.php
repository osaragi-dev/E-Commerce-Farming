<?php

namespace App\Filament\Resources\ProductDisplaySetting\Pages;

use App\Filament\Resources\ProductDisplaySetting\ProductDisplaySettingResource;
use Filament\Resources\Pages\EditRecord;

class EditProductDisplaySetting extends EditRecord
{
    protected static string $resource = ProductDisplaySettingResource::class;

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
