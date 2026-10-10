<?php

namespace App\Filament\Resources\Payments\Tables\Actions;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use App\Models\SystemFreezeState;
use App\Services\PaymentVoidWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class VoidPaymentAction
{
    public static function make(): Action
    {
        return Action::make('voidPayment')
            ->label('Void')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Paid && $record->canBeVoided() && ! $record->hasActiveVoidRequest())
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->requiresConfirmation()
            ->modalHeading('Void Payment')
            ->modalDescription(fn (Payment $record): string => 'Submit a void request for order '.$record->order?->order_number.'. If no approval levels are configured, the payment will be voided immediately.')
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(5000)])
            ->modalSubmitActionLabel('Submit Void')
            ->action(function (Payment $record, array $data, Component $livewire): void {
                $request = app(PaymentVoidWorkflow::class)->request($record, Auth::user(), $data['reason']);

                Notification::make()
                    ->title($request->approval_status === 'approved' ? 'Payment voided successfully.' : 'Void request submitted.')
                    ->success()
                    ->send();

                if ($request->approval_status === 'requested') {
                    $livewire->redirect(PaymentResource::getUrl('view', ['record' => $record]));
                } elseif ($livewire instanceof ViewPayment) {
                    $livewire->record = $record->fresh();
                }
            })
            // Raw permission string, resilient to PaymentPolicy::void()
            // being wiped by shield:generate — see
            // AdvanceOrderStatusAction for the full explanation.
            ->authorize('Void:Payment');
    }
}
