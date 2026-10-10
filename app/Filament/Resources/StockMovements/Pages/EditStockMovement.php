<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\StockMovement;
use App\Services\ApprovalWorkflow;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditStockMovement extends EditRecord
{
    protected static string $resource = StockMovementResource::class;

    public bool $saveAsDraft = false;

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->label('Save')
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
        $this->save();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $isDraft = $this->record->status === StockMovementStatus::Draft;
        $isRevised = $this->record->approval_status?->value === 'revised';

        $data['status'] = $this->saveAsDraft
            ? StockMovementStatus::Draft
            : (($isDraft || $isRevised)
                ? StockMovementStatus::Requested
                : $this->record->status);

        if (! $this->saveAsDraft && ($isDraft || $isRevised)) {
            $requiresApproval = app(ApprovalWorkflow::class)->requiresApproval($this->record);
            $data['approval_status'] = $requiresApproval
                ? StockMovementApprovalStatus::Requested
                : StockMovementApprovalStatus::Approved;
            $data['approval_level'] = $requiresApproval ? 1 : 0;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var StockMovement $movement */
        $movement = $this->record->refresh();

        if (
            $movement->status === StockMovementStatus::Requested
            && $movement->reserved_at === null
        ) {
            $movement->reserveRequestedStock(auth()->id());
        }

        if (
            $movement->status === StockMovementStatus::Draft
            && $movement->reserved_at !== null
        ) {
            $movement->releaseReservedStock(auth()->id());
        }
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless(StockMovementResource::canEdit($this->record), 403);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
