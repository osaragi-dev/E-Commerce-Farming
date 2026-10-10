<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockWasteApprovalStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    case Pending = 'pending';
    case Approved = 'approved';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Revised = 'revised';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Posted => 'Posted',
            self::Rejected => 'Rejected',
            self::Revised => 'Revision requested',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested, self::Pending => 'warning',
            self::Approved => 'success',
            self::Posted => 'info',
            self::Rejected => 'danger',
            self::Revised => 'gray',
            self::Cancelled => 'danger',
        };
    }
}
