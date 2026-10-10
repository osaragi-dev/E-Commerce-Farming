<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Enums\GoodsReceiptApprovalStatus;
use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Services\ReceiveGoodsReceiptStock;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateGoodsReceipt extends CreateRecord
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
        $catalog = collect($data['item_catalog'] ?? [])->keyBy('purchase_order_item_id');
        foreach ($data['items'] ?? [] as $row) {
            $catalog->put($row['purchase_order_item_id'], [...($catalog->get($row['purchase_order_item_id']) ?? []), ...$row]);
        }
        $data['items'] = $catalog->values()->all();
        $data['received_at'] = $data['received_date'].' '.substr((string) $data['received_time'], 0, 5).':00';
        $data['record_status'] = $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved;
        unset($data['received_date'], $data['received_time'], $data['item_search'], $data['item_catalog'], $data['warehouse_label'], $data['vendor_label']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): GoodsReceipt {
            $itemRows = $data['items'] ?? [];
            unset($data['items']);

            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->with('items')->findOrFail($data['purchase_order_id']);
            if ($purchaseOrder->record_status !== RecordStatus::Saved
                || $purchaseOrder->approval_status !== PurchaseOrderApprovalStatus::Approved
                || $purchaseOrder->closing_status === 'closed') {
                throw ValidationException::withMessages(['purchase_order_id' => 'Select an approved and open Purchase Order.']);
            }

            $selectedRows = collect($itemRows)->filter(fn (array $row): bool => (bool) ($row['is_selected'] ?? false));
            if ($selectedRows->isEmpty() && ! $this->saveAsDraft) {
                throw ValidationException::withMessages(['items' => 'Select at least one item to receive.']);
            }

            $receipt = new GoodsReceipt;
            $receipt->fill($data);
            $receipt->record_status = $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved;
            $receipt->save();

            foreach ($selectedRows as $row) {
                $orderItem = $purchaseOrder->items()->lockForUpdate()->find($row['purchase_order_item_id'] ?? null);
                $received = (float) Quantity::validated($row['quantity_received'] ?? 0, 'quantity_received', true);
                $accepted = (float) Quantity::validated($row['accepted_quantity'] ?? 0, 'accepted_quantity', true);
                $rejected = (float) Quantity::validated($row['rejected_quantity'] ?? 0, 'rejected_quantity', true);

                if (! $orderItem) {
                    throw ValidationException::withMessages(['items' => 'An item no longer belongs to the selected Purchase Order.']);
                }

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

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title($this->saveAsDraft ? 'Goods Receipt saved as draft.' : 'Goods Receipt submitted.')
            ->success();
    }
}
