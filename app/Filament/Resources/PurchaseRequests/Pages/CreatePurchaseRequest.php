<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Enums\RecordStatus;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\PurchaseRequests\Schemas\PurchaseRequestForm;
use App\Models\PurchaseRequest;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreatePurchaseRequest extends CreateRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    public bool $saveAsDraft = false;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::FiveExtraLarge;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Create')
                ->color('success'),
            Action::make('saveDraft')
                ->label('Save as Draft')
                ->color('warning')
                ->action('saveDraft'),
            $this->getCancelFormAction(),
        ];
    }

    public function saveDraft(): void
    {
        $this->saveAsDraft = true;

        try {
            $this->create();
        } finally {
            $this->saveAsDraft = false;
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['items'] = PurchaseRequestForm::selectedItemsFromFormState($data);
        unset($data['selected_item_rows']);
        $data['record_status'] = $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): PurchaseRequest {
            $items = $data['items'];
            unset($data['items']);

            $record = PurchaseRequest::query()->create($data);
            $record->items()->createMany($items);

            return $record;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
