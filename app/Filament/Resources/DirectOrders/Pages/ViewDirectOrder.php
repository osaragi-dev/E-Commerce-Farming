<?php

namespace App\Filament\Resources\DirectOrders\Pages;

use App\Filament\Actions\ApproveWorkflowAction;
use App\Filament\Actions\RejectWorkflowAction;
use App\Filament\Actions\ReviseWorkflowAction;
use App\Filament\Resources\DirectOrders\DirectOrderResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Livewire\Attributes\On;

class ViewDirectOrder extends ViewRecord
{
    protected static string $resource = DirectOrderResource::class;

    protected static ?string $title = 'View Direct Order';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label('Edit')
                ->url(fn (): string => DirectOrderResource::getUrl('edit', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => DirectOrderResource::canEdit($this->getRecord())),
            DeleteAction::make()->visible(fn (): bool => DirectOrderResource::canDelete($this->getRecord())),
            ApproveWorkflowAction::make(),
            ReviseWorkflowAction::make(),
            RejectWorkflowAction::make(),
        ];
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
            'items.item.unit', 'requestedBy', 'warehouse', 'vendor',
        ]);
    }
}
