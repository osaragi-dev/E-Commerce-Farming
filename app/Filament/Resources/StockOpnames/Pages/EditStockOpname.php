<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Enums\StockOpnameApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Services\ApprovalWorkflow;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditStockOpname extends EditRecord
{
    protected static string $resource = StockOpnameResource::class;

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
                ->visible(fn (): bool => $this->record->approval_status !== StockOpnameApprovalStatus::Revised)
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
        $isRevised = $this->record->approval_status === StockOpnameApprovalStatus::Revised;
        $data['status'] = $isRevised
            ? $this->record->status
            : ($this->saveAsDraft ? StockOpnameStatus::Draft : StockOpnameStatus::Counting);

        if ($isRevised) {
            $requiresApproval = app(ApprovalWorkflow::class)->requiresApproval($this->record);
            $data['approval_status'] = $requiresApproval
                ? StockOpnameApprovalStatus::Requested
                : StockOpnameApprovalStatus::Approved;
            $data['approval_level'] = $requiresApproval ? 1 : 0;
        }

        return $data;
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless(StockOpnameResource::canEdit($this->record), 403);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', [
            'record' => $this->record,
        ]);
    }
}
