<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Actions\ApproveWorkflowAction;
use App\Filament\Actions\RejectWorkflowAction;
use App\Filament\Actions\ReviseWorkflowAction;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Livewire\Attributes\On;

class ViewPurchaseRequest extends ViewRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected static ?string $title = 'View Purchase Request';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [ApproveWorkflowAction::make(), ReviseWorkflowAction::make(), RejectWorkflowAction::make()];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->loadRelations();
    }

    #[On('refresh-page')]
    public function refreshPage(): void
    {
        $this->record = $this->getRecord()->fresh();
        $this->loadRelations();
    }

    private function loadRelations(): void
    {
        $this->record->loadMissing([
            'approvalLogs.actedBy', 'approvalSetting.levels', 'createdBy', 'updatedBy', 'deletedBy',
            'items.item.unit', 'requestedBy', 'warehouse',
        ]);
    }
}
