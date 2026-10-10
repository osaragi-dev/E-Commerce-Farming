<?php

namespace App\Filament\Resources\Orders\Tables\Actions;

use App\Enums\PaymentMethod;
use App\Filament\Support\Rupiah;
use App\Models\Order;
use App\Models\SystemFreezeState;
use App\Services\OrderPaymentRecreationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;

class CreatePaymentAction
{
    public static function make(): Action
    {
        return Action::make('createPayment')
            ->label('Create Payment')
            ->link()
            ->visible(fn (Order $record): bool => $record->canRecreatePayment())
            ->authorize(fn (Order $record): bool => auth()->user()->can('Create:Payment') && auth()->user()->can('update', $record))
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->modalHeading('Create Payment')
            ->modalSubmitActionLabel('Create Payment')
            ->fillForm(fn (Order $record): array => [
                'expected_payment_id' => $record->payment->id,
                'method' => $record->payment->method === PaymentMethod::Invoice ? PaymentMethod::Cash->value : $record->payment->method->value,
            ])
            ->schema([
                TextEntry::make('order_number')->label('Order Number')->state(fn (Order $record): string => $record->order_number),
                TextEntry::make('amount')->label('Amount')->state(fn (Order $record): string => Rupiah::formatWithPrefix($record->total)),
                Hidden::make('expected_payment_id')->required(),
                Select::make('method')->label('Payment Method')->required()->options([
                    PaymentMethod::Cash->value => 'Cash',
                    PaymentMethod::Qris->value => 'QRIS',
                    PaymentMethod::BankTransfer->value => 'Bank Transfer',
                ]),
            ])
            ->action(function (Order $record, array $data): void {
                app(OrderPaymentRecreationService::class)->recreate($record, PaymentMethod::from($data['method']), auth()->user(), $data['expected_payment_id']);
                Notification::make()->title('Payment created successfully.')->success()->send();
            });
    }
}
