<?php

namespace App\Filament\Resources\Orders\Tables\Actions;

use App\Enums\StockReservationStatus;
use App\Models\Order;
use App\Models\SystemFreezeState;
use App\Services\OrderStockReservationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class RetryReservationAction
{
    public static function make(): Action
    {
        return Action::make('retryReservation')
            ->label('Retry Reservation')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (Order $record): bool => $record->stock_reservation_status === StockReservationStatus::Pending)
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->requiresConfirmation()
            ->modalHeading('Retry Stock Reservation')
            ->modalDescription(fn (Order $record): string => 'Retry stock reservation for order '.$record->order_number.'?')
            ->action(function (Order $record): void {
                $result = app(OrderStockReservationService::class)->reservePending($record, Auth::id());

                if ($result['reserved']) {
                    Notification::make()
                        ->title('Stock reservation completed.')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Stock reservation is still pending.')
                    ->body($result['message'] ?? 'Stock is not sufficient yet.')
                    ->warning()
                    ->send();
            })
            ->authorize('Update:Order');
    }
}
