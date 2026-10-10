<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Enums\StockOpnameStatus;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateStockOpname extends CreateRecord
{
    protected static string $resource = StockOpnameResource::class;

    public bool $saveAsDraft = false;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Create')
                ->color('success'),
            Action::make('saveDraft')
                ->label('Save as Draft')
                ->color('gray')
                ->action('saveDraft'),
            $this->getCancelFormAction(),
        ];
    }

    public function saveDraft(): void
    {
        $this->saveAsDraft = true;
        $this->create();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = $this->saveAsDraft
            ? StockOpnameStatus::Draft
            : StockOpnameStatus::Counting;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', [
            'record' => $this->record,
        ]);
    }
}
