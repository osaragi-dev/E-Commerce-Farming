<?php

namespace App\Filament\Resources\PurchaseInvoices\Pages;

use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Filament\Resources\PurchaseInvoices\Schemas\PurchaseInvoiceForm;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePurchaseInvoice extends CreateRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    public bool $saveAsDraft = false;

    public function mount(): void
    {
        parent::mount();
        $purchaseOrderId = request()->query('purchase_order_id');

        if (filled($purchaseOrderId)) {
            $this->form->fill(PurchaseInvoiceForm::initialData((string) $purchaseOrderId));
        }
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
        $order = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);
        $data['items'] = PurchaseInvoiceForm::validatedItems($data['items'] ?? [], $order);
        $data['subtotal'] = collect($data['items'])->sum(fn (array $line): float => (float) $line['line_total']);
        $data['grand_total'] = max(0, $data['subtotal'] + (float) ($data['tax_amount'] ?? 0) - (float) ($data['discount_amount'] ?? 0));
        $data['record_status'] = $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): PurchaseInvoice {
            $items = $data['items'];
            unset($data['items'], $data['purchase_order_label'], $data['vendor_label'], $data['item_search']);

            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($data['purchase_order_id']);

            if ($purchaseOrder->approval_status !== PurchaseOrderApprovalStatus::Approved || $purchaseOrder->closing_status === 'closed') {
                throw ValidationException::withMessages(['purchase_order_id' => 'Select an open, approved Purchase Order.']);
            }

            $items = PurchaseInvoiceForm::validatedItems($items, $purchaseOrder);
            $data['vendor_id'] = $purchaseOrder->vendor_id;
            $data['subtotal'] = collect($items)->sum(fn (array $line): float => (float) $line['line_total']);
            $data['grand_total'] = max(0, $data['subtotal'] + (float) ($data['tax_amount'] ?? 0) - (float) ($data['discount_amount'] ?? 0));

            $invoice = PurchaseInvoice::query()->create($data);
            $invoice->items()->createMany($items);

            return $invoice;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
