<?php

namespace App\Filament\Resources\Payments\Tables\Actions;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\SystemFreezeState;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\ImageEntry;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class VerifyPaymentAction
{
    public static function make(): Action
    {
        return Action::make('verifyPayment')
            ->label('Confirm Payment')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Payment $record): bool => $record->canStartConfirmation())
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->schema([
                ImageEntry::make('current_payment_proof')
                    ->label('Current Payment Proof')
                    ->state(fn (Payment $record): ?string => $record->currentProof()?->file_path)
                    ->disk('public')
                    ->visible(fn (Payment $record): bool => $record->method?->requiresProof() && $record->currentProof() !== null),
                FileUpload::make('payment_proof')
                    ->label(fn (Payment $record): string => 'Payment Proof ('.$record->method->getLabel().')')
                    ->image()
                    ->disk('public')
                    ->directory('payment-proofs')
                    ->visible(fn (Payment $record): bool => $record->method?->requiresProof() && $record->status === PaymentStatus::Unpaid && $record->currentProof() === null)
                    ->required(fn (Payment $record): bool => $record->method?->requiresProof() && $record->currentProof() === null),
            ])
            ->requiresConfirmation()
            ->modalHeading('Confirm Payment')
            ->modalDescription(fn (Payment $record): string => match ($record->method) {
                PaymentMethod::Cash => 'Confirm that cash has been received in full for order '.$record->order?->order_number.'.',
                PaymentMethod::Invoice => 'Confirm that invoice payment has been received in full for order '.$record->order?->order_number.'.',
                PaymentMethod::Qris, PaymentMethod::BankTransfer => 'Are you sure this payment proof is valid?',
            })
            ->modalSubmitActionLabel('Confirm Payment')
            ->action(function (Payment $record, array $data): void {
                $record->confirm(Auth::user(), $data['payment_proof'] ?? null);

                Notification::make()
                    ->title('Payment confirmed successfully.')
                    ->success()
                    ->send();
            })
            // Raw permission string, resilient to PaymentPolicy::verify()
            // being wiped by shield:generate — see
            // AdvanceOrderStatusAction for the full explanation.
            ->authorize('Verify:Payment');
    }
}
