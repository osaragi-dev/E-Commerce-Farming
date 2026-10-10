<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasLabel
{
    case Unpaid = 'unpaid';
    case WaitingConfirm = 'waiting_confirm';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case UnpaidCancel = 'unpaid_cancel';

    // Payment was cancelled/voided (e.g. Cancel Order before Packing, or an
    // explicit Void action). Distinct from Rejected: Rejected means the
    // proof itself was invalid; Void means the payment was cancelled.
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::WaitingConfirm => 'Waiting Confirm',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::UnpaidCancel => 'Unpaid-Cancel',
            self::Void => 'Void',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unpaid, self::WaitingConfirm => 'warning',
            self::Paid => 'success',
            self::Rejected => 'danger',
            self::UnpaidCancel => 'danger',
            self::Void => 'danger',
        };
    }
}
