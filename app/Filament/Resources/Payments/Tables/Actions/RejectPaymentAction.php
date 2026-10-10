<?php

namespace App\Filament\Resources\Payments\Tables\Actions;

use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\SystemFreezeState;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class RejectPaymentAction
{
    /** @var array<string, string> */
    private const REASONS = [
        'Payment amount does not match' => 'Payment amount does not match',
        'Invalid payment proof' => 'Invalid payment proof',
        'Payment cannot be verified' => 'Payment cannot be verified',
        'Other' => 'Other',
    ];

    public static function make(): Action
    {
        return Action::make('rejectPayment')
            ->label('Reject Payment')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Payment $record): bool => $record->hasVerifiableProof())
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->schema([
                Select::make('reason_preset')
                    ->label('Reason')
                    ->options(self::REASONS)
                    ->required()
                    ->native(false)
                    ->live(),
                Textarea::make('reason_other')
                    ->label('Reason Detail')
                    ->required(fn ($get): bool => $get('reason_preset') === 'Other')
                    ->visible(fn ($get): bool => $get('reason_preset') === 'Other')
                    ->rows(3),
            ])
            ->requiresConfirmation()
            ->modalHeading('Reject Payment')
            ->modalDescription(fn (Payment $record): string => 'A rejection reason is required. The customer can upload a new payment proof within '.PaymentSetting::current()->proof_resubmission_hours.' hours after rejection.')
            ->modalSubmitActionLabel('Reject Payment')
            ->action(function (Payment $record, array $data): void {
                $reason = $data['reason_preset'] === 'Other'
                    ? $data['reason_other']
                    : $data['reason_preset'];

                $record->reject(Auth::user(), $reason);

                Notification::make()
                    ->title('Payment rejected.')
                    ->danger()
                    ->send();
            })
            // Raw permission string, resilient to PaymentPolicy::reject()
            // being wiped by shield:generate — see
            // AdvanceOrderStatusAction for the full explanation.
            ->authorize('Reject:Payment');
    }
}
