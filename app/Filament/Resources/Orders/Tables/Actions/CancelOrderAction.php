<?php

namespace App\Filament\Resources\Orders\Tables\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\SystemFreezeState;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class CancelOrderAction
{
    public static function make(): Action
    {
        return Action::make('cancelOrder')
            ->label('Cancel')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Order $record): bool => $record->canBeCancelled()
                && ! ($record->payment?->hasActiveVoidRequest() ?? false)
                && ($record->payment?->status !== PaymentStatus::Paid || Auth::user()->can('Void:Payment')))
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->schema([
                Textarea::make('reason')
                    ->label('Cancellation Reason')
                    ->rows(3)
                    ->required()
                    ->maxLength(5000)
                    ->placeholder('Enter cancellation reason'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Cancel Order')
            ->modalDescription(fn (Order $record): string => 'Cancel order '.$record->order_number.'? Stock will return to the original warehouse when cancellation completes. For shipped orders, confirm the goods have returned. Paid payments require void approval when configured; the order remains on hold until approval is complete.')
            ->modalSubmitActionLabel('Cancel Order')
            ->action(function (Order $record, array $data): void {
                $record->cancel(Auth::user(), $data['reason'] ?? null);

                Notification::make()
                    ->title($record->fresh()->status === OrderStatus::Cancelled
                        ? 'Order canceled successfully.'
                        : 'Cancellation requested. Awaiting void payment approval.')
                    ->success()
                    ->send();
            })
            ->authorize('delete');
    }
}
