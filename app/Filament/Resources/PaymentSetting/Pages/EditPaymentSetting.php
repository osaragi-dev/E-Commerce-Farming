<?php

namespace App\Filament\Resources\PaymentSetting\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PaymentSetting\PaymentSettingResource;
use Filament\Resources\Pages\EditRecord;

class EditPaymentSetting extends EditRecord
{
    protected static string $resource = PaymentSettingResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->redirect(PaymentResource::getUrl('index'), navigate: false);
    }

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
