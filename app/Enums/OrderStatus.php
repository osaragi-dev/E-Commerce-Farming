<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case WaitingPayment = 'waiting_payment';
    case WaitingConfirmation = 'waiting_confirmation';
    case ReadyToProcess = 'ready_to_process';
    case Packing = 'packing';
    case Shipping = 'shipping';
    case ReadyForPickup = 'ready_for_pickup';
    case PickedUp = 'picked_up';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::WaitingPayment => 'Waiting Payment',
            self::WaitingConfirmation => 'Waiting Confirmation',
            self::ReadyToProcess => 'Ready to Process',
            self::Packing => 'Packing',
            self::Shipping => 'Shipping',
            self::ReadyForPickup => 'Shipping',
            self::PickedUp => 'Done',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    public function forAdminDisplay(): self
    {
        return match ($this) {
            self::WaitingConfirmation => self::ReadyToProcess,
            default => $this,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::WaitingPayment, self::WaitingConfirmation => 'warning',
            self::ReadyToProcess => 'success',
            self::Packing => 'info',
            self::Shipping, self::ReadyForPickup => 'primary',
            self::PickedUp, self::Done => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * The next status an admin may move this order to for the given shipping method.
     *
     * The application uses delivery-only fulfillment; legacy pickup states are retained
     * only for compatibility and are treated as terminal/delivery equivalents.
     *
     * WaitingPayment -> WaitingConfirmation and WaitingConfirmation -> ReadyToProcess are
     * listed here for API completeness (this method describes the generic pipeline order).
     * In the admin workflow, the first transition happens when QRIS proof is submitted
     * (see Order::markWaitingConfirmation()), and the
     * second happens automatically when Payment::confirm() marks the payment Paid (see
     * Order::markReadyToProcess()). Order::nextStatus() additionally permits
     * WaitingConfirmation -> Packing while payment remains WaitingConfirm.
     */
    public function next(ShippingMethod $shippingMethod): ?self
    {
        return match ($this) {
            self::WaitingPayment => self::WaitingConfirmation,
            self::WaitingConfirmation => self::ReadyToProcess,
            self::ReadyToProcess => self::Packing,
            self::Packing => self::Shipping,
            self::ReadyForPickup, self::Shipping => self::Done,
            self::PickedUp => self::Done,
            self::Done, self::Cancelled => null,
        };
    }

    /** @return list<self> */
    public static function activeStates(): array
    {
        return [
            self::WaitingPayment,
            self::WaitingConfirmation,
            self::ReadyToProcess,
            self::Packing,
            self::Shipping,
        ];
    }
}
