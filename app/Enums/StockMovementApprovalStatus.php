<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockMovementApprovalStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revised = 'revised';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Revised => 'Revised',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested => 'info',
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Revised => 'info',
            self::Cancelled => 'gray',
        };
    }
}
