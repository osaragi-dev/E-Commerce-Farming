<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Enums\GoodsReceiptApprovalStatus;
use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\GoodsReceipts\Schemas\GoodsReceiptForm;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Services\ReceiveGoodsReceiptStock;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditGoodsReceipt extends EditRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    public bool $saveAsDraft = false;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label('Save & Submit')->color('success'),
            Action::make('saveDraft')->label('Save as Draft')->color('warning')->action('saveDraft'),
            $this->getCancelFormAction(),
        ];
    }

    public function saveDraft(): void
    {
        $this->saveAsDraft = true;

        try {
            $this->save();
        } finally {
            $this->saveAsDraft = false;
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var GoodsReceipt $receipt */
        $receipt = $this->record;
        $items = GoodsReceiptForm::existingRows($receipt);

        return [
            ...$data,
            'received_date' => $receipt->received_at?->toDateString(),
            'received_time' => $receipt->received_at?->format('H:i'),
            'item_catalog' => $items,
            'items' => $items,
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $catalog = GoodsReceiptForm::mergeRows($data['item_catalog'] ?? [], $data['items'] ?? []);
        $data['items'] = $catalog;
        $data['received_at'] = $data['received_date'].' '.substr((string) $data['received_time'], 0, 5).':00';
        $data['record_status'] = $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved;
        unset($data['received_date'], $data['received_time'], $data['item_search'], $data['item_catalog'], $data['warehouse_label'], $data['vendor_label']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): GoodsReceipt {
            /** @var GoodsReceipt $receipt */
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($record->getKey());
            if ($receipt->record_status !== RecordStatus::Draft || $receipt->trashed()) {
                throw ValidationException::withMessages(['record' => 'Only active draft Goods Receipts can be edited.']);
            }

            $itemRows = collect($data['items'] ?? []);
            unset($data['items']);

            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->with('items')->findOrFail($data['purchase_order_id']);
            if ($purchaseOrder->record_status !== RecordStatus::Saved
                || $purchaseOrder->approval_status !== PurchaseOrderApprovalStatus::Approved
                || $purchaseOrder->closing_status === 'closed') {
                throw ValidationException::withMessages(['purchase_order_id' => 'Select an approved and open Purchase Order.']);
            }

            $selectedRows = $itemRows->filter(fn (array $row): bool => (bool) ($row['is_selected'] ?? false));
            if ($selectedRows->isEmpty() && ! $this->saveAsDraft) {
                throw ValidationException::withMessages(['items' => 'Select at least one item to receive.']);
            }

            $receipt->fill($data);
            $receipt->record_status = $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved;
            $receipt->save();
            $receipt->items()->delete();

            foreach ($selectedRows as $row) {
                $orderItem = $purchaseOrder->items()->lockForUpdate()->find($row['purchase_order_item_id'] ?? null);
                if (! $orderItem) {
                    throw ValidationException::withMessages(['items' => 'An item no longer belongs to the selected Purchase Order.']);
                }

                $received = (float) Quantity::validated($row['quantity_received'] ?? 0, 'quantity_received', true);
                $accepted = (float) Quantity::validated($row['accepted_quantity'] ?? 0, 'accepted_quantity', true);
                $rejected = (float) Quantity::validated($row['rejected_quantity'] ?? 0, 'rejected_quantity', true);
                if ($this->saveAsDraft && $received <= 0) {
                    continue;
                }

                if (! $this->saveAsDraft && ($received <= 0 || abs(($accepted + $rejected) - $received) > 0.0001)) {
                    throw ValidationException::withMessages(['items' => 'Received quantity must be positive and equal accepted plus rejected quantity.']);
                }

                if (! $this->saveAsDraft && $rejected > 0 && blank($row['rejection_reason'] ?? null)) {
                    throw ValidationException::withMessages(['items' => 'Enter a rejection reason for each rejected item.']);
                }

                if (! $this->saveAsDraft && blank($row['condition'] ?? null)) {
                    throw ValidationException::withMessages(['items' => 'Select a condition for each received item.']);
                }

                $alreadyReserved = (float) GoodsReceiptItem::query()
                    ->where('purchase_order_item_id', $orderItem->id)
                    ->whereHas('goodsReceipt', fn ($query) => $query
                        ->where('id', '!=', $receipt->id)
                        ->where('record_status', RecordStatus::Saved)
                        ->whereIn('approval_status', [
                            GoodsReceiptApprovalStatus::Requested,
                            GoodsReceiptApprovalStatus::Pending,
                            GoodsReceiptApprovalStatus::Approved,
                        ]))
                    ->sum('quantity_received');

                if (! $this->saveAsDraft && $alreadyReserved + $received > (float) $orderItem->qty_order + 0.0001) {
                    throw ValidationException::withMessages(['items' => 'Received quantity exceeds the remaining quantity on the Purchase Order.']);
                }

                $receipt->items()->create([
                    'purchase_order_item_id' => $orderItem->id,
                    'quantity_received' => $received,
                    'accepted_quantity' => $accepted,
                    'rejected_quantity' => $rejected,
                    'condition' => $row['condition'] ?? null,
                    'rejection_reason' => $row['rejection_reason'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            if ($receipt->approval_status === GoodsReceiptApprovalStatus::Approved) {
                app(ReceiveGoodsReceiptStock::class)->handle($receipt, auth('web')->id());
            }

            return $receipt;
        });
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();
        abort_unless(GoodsReceiptResource::canEdit($this->record), 403);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->title($this->saveAsDraft ? 'Goods Receipt draft saved.' : 'Goods Receipt submitted.')
            ->success();
    }
}
