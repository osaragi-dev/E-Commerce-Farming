<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PurchaseOrderApprovalStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Requested = 'requested';
    case Approved = 'approved';
    case Revised = 'revised';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return str($this->value)->headline()->toString();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Requested => 'warning',
            self::Approved => 'success',
            self::Revised => 'info',
            self::Rejected => 'danger',
        };
    }
}
