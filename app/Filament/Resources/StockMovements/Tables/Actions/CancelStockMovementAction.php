<?php

namespace App\Filament\Resources\StockMovements\Tables\Actions;

use App\Enums\StockMovementApprovalStatus;
use App\Models\StockMovement;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Livewire\Component;

class CancelStockMovementAction
{
    public static function make(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            ->visible(fn (StockMovement $record): bool => ! $record->trashed()
                && $record->approval_status === StockMovementApprovalStatus::Requested
                && $record->canBeCancelled())
            ->authorize(fn (StockMovement $record): bool => (auth()->user()?->can('view', $record) ?? false)
                && (auth()->user()?->can('Cancel:StockMovement') ?? false))
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->rows(3),
            ])
            ->modalHeading('Cancel Stock Movement')
            ->modalSubmitActionLabel('Cancel Movement')
            ->action(function (StockMovement $record, array $data, Component $livewire): void {
                $record->cancel($data['reason'], auth()->id());
                $livewire->dispatch('refresh-page');

                Notification::make()
                    ->title('Stock movement cancelled.')
                    ->success()
                    ->send();
            });
    }
}
