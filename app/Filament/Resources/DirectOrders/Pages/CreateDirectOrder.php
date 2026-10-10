<?php

namespace App\Filament\Resources\DirectOrders\Pages;

use App\Enums\RecordStatus;
use App\Filament\Resources\DirectOrders\DirectOrderResource;
use App\Filament\Resources\DirectOrders\Schemas\DirectOrderForm;
use App\Models\DirectOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateDirectOrder extends CreateRecord
{
    protected static string $resource = DirectOrderResource::class;

    public bool $saveAsDraft = false;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Create')->color('success'),
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
        $data['items'] = DirectOrderForm::selectedItems($data['items'] ?? [], $data['warehouse_id']);
        $subtotal = collect($data['items'])->sum('total_price');
        $taxAmount = (float) ($data['tax_amount'] ?? 0);
        $discountAmount = (float) ($data['discount_amount'] ?? 0);
        $data['subtotal'] = $subtotal;
        $data['tax_amount'] = $taxAmount;
        $data['discount_amount'] = $discountAmount;
        $data['total_amount'] = $subtotal + $taxAmount - $discountAmount;
        $data['transaction_proof'] = collect($data['transaction_proof'])->first();
        $data['record_status'] = $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): DirectOrder {
            $items = $data['items'];
            unset($data['items']);

            $directOrder = DirectOrder::query()->create($data);
            $directOrder->items()->createMany($items);

            return $directOrder;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
