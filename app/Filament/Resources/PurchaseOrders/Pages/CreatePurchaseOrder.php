<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public bool $saveAsDraft = false;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::FiveExtraLarge;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Create')->color('success'),
            Action::make('saveDraft')->label('Save as Draft')->color('warning')->action('saveDraft'),
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
        $data['items'] = PurchaseOrderForm::validatedItems($data['items'] ?? [], (string) $data['purchase_request_id']);
        $data['subtotal'] = collect($data['items'])->sum(fn (array $item): float => (float) $item['total_price']);
        $data['total_amount'] = max(0, $data['subtotal'] + (float) ($data['tax_amount'] ?? 0) - (float) ($data['discount_amount'] ?? 0));
        $data['record_status'] = $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $items = $data['items'];
            unset($data['items']);

            $purchaseRequest = PurchaseRequest::query()->lockForUpdate()->findOrFail($data['purchase_request_id']);
            $vendor = Vendor::query()->lockForUpdate()->findOrFail($data['vendor_id']);

            if ($purchaseRequest->record_status !== RecordStatus::Saved
                || $purchaseRequest->approval_status !== PurchaseOrderApprovalStatus::Approved) {
                throw ValidationException::withMessages(['purchase_request_id' => 'Select an approved Purchase Request.']);
            }

            if ($vendor->record_status !== RecordStatus::Saved || ! $vendor->is_active || $vendor->approval_status?->value !== 'approved') {
                throw ValidationException::withMessages(['vendor_id' => 'Select an active, approved Vendor.']);
            }

            $items = PurchaseOrderForm::validatedItems($items, $purchaseRequest->id);
            $data['warehouse_id'] = $purchaseRequest->warehouse_id;
            $data['subtotal'] = collect($items)->sum(fn (array $item): float => (float) $item['total_price']);
            $data['total_amount'] = max(0, $data['subtotal'] + (float) ($data['tax_amount'] ?? 0) - (float) ($data['discount_amount'] ?? 0));

            $record = PurchaseOrder::query()->create($data);
            $record->items()->createMany($items);

            return $record;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
