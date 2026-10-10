<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Enums\RecordStatus;
use App\Enums\StockAdjustmentReason;
use App\Enums\StockAdjustmentSource;
use App\Enums\StockAdjustmentType;
use App\Enums\StockOpnameApprovalStatus;
use App\Filament\Resources\StockAdjustments\Schemas\StockAdjustmentForm;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Models\StockOpnameItem;
use App\Models\StockOpnameSession;
use App\Models\SystemFreezeState;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateStockAdjustment extends CreateRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected static bool $canCreateAnother = false;

    public bool $saveAsDraft = false;

    public function mount(): void
    {
        parent::mount();

        $source = StockAdjustmentSource::tryFrom((string) request()->query('source'))
            ?? StockAdjustmentSource::DirectAdjustment;
        $sessionId = request()->query('stock_opname_session_id');
        $session = $source === StockAdjustmentSource::StockOpname && filled($sessionId)
            ? StockOpnameSession::query()
                ->where('status', 'approved')
                ->where('approval_status', StockOpnameApprovalStatus::Approved->value)
                ->whereHas('items', fn ($query) => $query->where('variance_qty', '!=', 0)->whereNull('stock_adjustment_id'))
                ->with(['items.productVariant.product', 'items.productVariant.unit'])
                ->find($sessionId)
            : null;

        $this->form->fill([
            'source' => $session ? $source : StockAdjustmentSource::DirectAdjustment,
            'stock_opname_session_id' => $session?->id,
            'items' => $session ? StockAdjustmentForm::opnameItemData($session) : [],
            'created_at' => now(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $source = $data['source'] instanceof StockAdjustmentSource
            ? $data['source']
            : StockAdjustmentSource::from($data['source']);

        if ($source !== StockAdjustmentSource::StockOpname) {
            unset($data['items'], $data['stock_opname_session_id'], $data['adjustment_number']);
            $data['record_status'] = $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved;

            return StockAdjustment::query()->create($data);
        }

        return DB::transaction(function () use ($data): StockAdjustment {
            $session = StockOpnameSession::query()
                ->lockForUpdate()
                ->where('status', 'approved')
                ->where('approval_status', StockOpnameApprovalStatus::Approved->value)
                ->findOrFail($data['stock_opname_session_id'] ?? null);

            $sessionItems = StockOpnameItem::query()
                ->where('stock_opname_session_id', $session->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $items = $sessionItems
                ->filter(fn (StockOpnameItem $item): bool => (float) $item->variance_qty !== 0.0 && $item->stock_adjustment_id === null)
                ->values();
            $submittedItemIds = collect($data['items'] ?? [])->pluck('stock_opname_item_id')->sort()->values();
            $sessionItemIds = $sessionItems->pluck('id')->sort()->values();

            if ($items->isEmpty() || $sessionItemIds->all() !== $submittedItemIds->all()) {
                throw ValidationException::withMessages([
                    'data.stock_opname_session_id' => 'This stock opname has no unprocessed variance items. Refresh and try again.',
                ]);
            }

            $adjustmentNumber = StockAdjustment::nextAdjustmentNumber();
            $firstItem = $items->firstOrFail();
            $adjustment = StockAdjustment::query()->create([
                'adjustment_number' => $adjustmentNumber,
                'source' => StockAdjustmentSource::StockOpname,
                'record_status' => $this->saveAsDraft ? RecordStatus::Draft : RecordStatus::Saved,
                'warehouse_id' => $session->warehouse_id,
                'product_variant_id' => $firstItem->product_variant_id,
                'type' => $firstItem->variance_qty > 0
                    ? StockAdjustmentType::Tambah
                    : StockAdjustmentType::Kurang,
                'quantity' => ltrim((string) $firstItem->variance_qty, '-'),
                'reason' => StockAdjustmentReason::StokOpname,
                'created_at' => $data['created_at'] ?? now(),
            ]);

            foreach ($items as $item) {
                $submittedItem = collect($data['items'])->firstWhere('stock_opname_item_id', $item->id);
                $item->forceFill([
                    'stock_adjustment_id' => $adjustment->id,
                    'notes' => $submittedItem['notes'] ?? $item->notes,
                ])->save();
            }

            return $adjustment;
        });
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Create Adjustment')
                ->color('success')
                ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen),
            ...(request()->query('source') === StockAdjustmentSource::StockOpname->value
                ? []
                : [Action::make('saveDraft')
                    ->label('Save as Draft')
                    ->color('gray')
                    ->action('saveDraft')
                    ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)]),
            $this->getCancelFormAction(),
        ];
    }

    public function saveDraft(): void
    {
        $this->saveAsDraft = true;
        $this->create();
    }
}
