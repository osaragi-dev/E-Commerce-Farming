<?php

namespace App\Filament\Resources\DirectOrders\Pages;

use App\Enums\PurchaseRequestApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Resources\DirectOrders\DirectOrderResource;
use App\Filament\Resources\DirectOrders\Schemas\DirectOrderForm;
use App\Models\DirectOrder;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditDirectOrder extends EditRecord
{
    protected static string $resource = DirectOrderResource::class;

    public bool $saveAsDraft = false;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['items'] = DirectOrderForm::rowsForEdit($this->getRecord());
        $data['item_category'] = 'critical';
        $data['transaction_proof'] = [$data['transaction_proof']];

        return $data;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label('Save')->color('success'),
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
            $this->save();
        } finally {
            $this->saveAsDraft = false;
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
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

        if ($this->saveAsDraft) {
            $data = [
                ...$data,
                'do_number' => null,
                'record_status' => RecordStatus::Draft,
                'approval_status' => PurchaseRequestApprovalStatus::Pending,
                'approval_level' => 0,
                'approval_setting_id' => null,
            ];
        } elseif ($this->getRecord()->record_status === RecordStatus::Draft) {
            $data['record_status'] = RecordStatus::Saved;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): DirectOrder {
            $items = $data['items'];
            unset($data['items']);

            $record->update($data);
            $record->items()->delete();
            $record->items()->createMany($items);

            return $record;
        });
    }
}
