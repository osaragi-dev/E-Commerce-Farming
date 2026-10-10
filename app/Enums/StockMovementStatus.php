<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockMovementStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Approved = 'approved';
    case Shipped = 'shipped';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Shipped => 'In Transit',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Shipped => 'primary',
            self::Received => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function getProgress(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::Requested => 25,
            self::Approved => 50,
            self::Shipped => 75,
            self::Received => 100,
            self::Cancelled => 0,
        };
    }
}
