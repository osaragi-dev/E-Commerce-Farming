<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockWasteStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Saved = 'saved';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Saved => 'Saved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Saved => 'success',
            self::Cancelled => 'danger',
        };
    }
}
