<?php

namespace App\Filament\Actions;

use App\Models\User;
use App\Services\ApprovalWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class RejectWorkflowAction
{
    public static function make(): Action
    {
        return Action::make('rejectApproval')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Model $record): bool => ($actor = auth()->user()) instanceof User
                && app(ApprovalWorkflow::class)->canApprove($record, $actor))
            ->authorize(fn (Model $record): bool => ($actor = auth()->user()) instanceof User
                && app(ApprovalWorkflow::class)->canApprove($record, $actor))
            ->schema([
                Textarea::make('notes')
                    ->label('Reason')
                    ->required()
                    ->rows(4),
            ])
            ->modalHeading('Reject Approval')
            ->modalSubmitActionLabel('Reject')
            ->action(function (Model $record, array $data, Component $livewire): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                app(ApprovalWorkflow::class)->reject($record, $actor, $data['notes']);
                $record->refresh();

                if (property_exists($livewire, 'record') && method_exists($livewire, 'getRecord')) {
                    $livewire->record = $livewire->getRecord()->fresh(array_keys($livewire->getRecord()->getRelations()));
                }

                $livewire->dispatch('refresh-page');

                Notification::make()
                    ->title('Approval rejected.')
                    ->danger()
                    ->send();
            });
    }
}
