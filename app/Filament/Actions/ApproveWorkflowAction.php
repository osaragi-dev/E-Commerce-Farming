<?php

namespace App\Filament\Actions;

use App\Models\User;
use App\Services\ApprovalWorkflow;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class ApproveWorkflowAction
{
    public static function make(): Action
    {
        return Action::make('approve')
            ->label(fn (Model $record): string => 'Approve Level '.max(1, (int) $record->getAttribute('approval_level')))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Model $record): bool => ($actor = auth()->user()) instanceof User
                && app(ApprovalWorkflow::class)->canApprove($record, $actor))
            ->authorize(fn (Model $record): bool => ($actor = auth()->user()) instanceof User
                && app(ApprovalWorkflow::class)->canApprove($record, $actor))
            ->requiresConfirmation()
            ->action(function (Model $record, Component $livewire): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                app(ApprovalWorkflow::class)->approve($record, $actor);
                $record->refresh();

                if (property_exists($livewire, 'record') && method_exists($livewire, 'getRecord')) {
                    $livewire->record = $livewire->getRecord()->fresh(array_keys($livewire->getRecord()->getRelations()));
                }

                $livewire->dispatch('refresh-page');

                Notification::make()
                    ->title('Approval recorded.')
                    ->success()
                    ->send();
            });
    }
}
