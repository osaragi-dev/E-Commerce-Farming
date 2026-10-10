<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockOpnameStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Frozen = 'frozen';
    case Counting = 'counting';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Frozen => 'Frozen',
            self::Counting => 'Counting',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Finalized => 'Finalized',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Frozen => 'danger',
            self::Counting => 'warning',
            self::Submitted => 'info',
            self::Approved => 'success',
            self::Finalized => 'success',
            self::Cancelled => 'danger',
        };
    }
}
