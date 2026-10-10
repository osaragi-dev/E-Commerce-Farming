<?php

namespace App\Filament\Resources\Orders\Tables\Actions;

use App\Enums\OrderStatus;
use App\Enums\StockReservationStatus;
use App\Models\Order;
use App\Models\SystemFreezeState;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class AdvanceOrderStatusAction
{
    public static function make(): Action
    {
        return Action::make('advanceStatus')
            ->label(fn (Order $record): string => self::labelFor($record))
            ->icon('heroicon-o-arrow-right-circle')
            ->color(fn (Order $record): string => $record->nextStatus()?->getColor() ?? 'primary')
            ->visible(fn (Order $record): bool => $record->nextStatus() !== null)
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record): string => self::labelFor($record))
            ->modalDescription(
                fn (Order $record): string => 'Order '.$record->order_number.' will be moved to "'.$record->nextStatus()?->getLabel().'". Continue?'
            )
            ->action(function (Order $record): void {
                if ($record->nextStatus() === OrderStatus::Shipping
                    && $record->stock_reservation_status !== StockReservationStatus::Reserved) {
                    Notification::make()
                        ->title('Reserve this order\'s stock with Retry Reservation before shipping.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $record->advanceStatus(Auth::user());
                } catch (InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Order status updated successfully.')
                    ->success()
                    ->send();
            })
            // Raw permission string, not the Policy method name
            // ('advanceStatus') — deliberately resilient to
            // OrderPolicy::advanceStatus() being wiped by
            // `php artisan shield:generate` (see that method's docblock).
            // Spatie's Gate::before intercepts this exact permission
            // string before Laravel ever tries to resolve a Policy method.
            ->authorize('AdvanceStatus:Order');
    }

    private static function labelFor(Order $record): string
    {
        return match ($record->nextStatus()) {
            OrderStatus::Packing => 'Packing',
            OrderStatus::Shipping => 'Shipping',
            OrderStatus::Done => 'Done',
            default => 'Advance Status',
        };
    }
}
