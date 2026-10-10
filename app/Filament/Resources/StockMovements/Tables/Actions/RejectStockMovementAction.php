<?php

namespace App\Filament\Resources\StockMovements\Tables\Actions;

use App\Enums\StockMovementStatus;
use App\Models\StockMovement;
use App\Models\SystemFreezeState;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class RejectStockMovementAction
{
    public static function make(): Action
    {
        return Action::make('rejectDispatch')
            ->label('Reject Dispatch')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (StockMovement $record): bool => ! $record->trashed()
                && $record->status === StockMovementStatus::Shipped)
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->authorize(fn (StockMovement $record): bool => (auth()->user()?->can('view', $record) ?? false)
                && (auth()->user()?->can('RejectDispatch:StockMovement') ?? false))
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->rows(4)
                    ->placeholder('Explain why this dispatch is being rejected...'),
            ])
            ->modalHeading('Reject Dispatch')
            ->modalSubmitActionLabel('Reject Dispatch')
            ->modalDescription('The movement will return to Requested and the shipped stock will be returned to Reserved in the source warehouse.')
            ->action(function (StockMovement $record, array $data, Component $livewire): void {
                $record->rejectDispatch($data['reason'], Auth::id());
                $livewire->dispatch('refresh-page');

                Notification::make()
                    ->title('Dispatch rejected.')
                    ->danger()
                    ->send();
            });
    }
}
