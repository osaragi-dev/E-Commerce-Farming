<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockWasteType: string implements HasLabel
{
    case Expired = 'expired';
    case Damaged = 'damaged';
    case Overproduction = 'overproduction';
    case Spoiled = 'spoiled';
    case Sample = 'sample';

    public function getLabel(): string
    {
        return match ($this) {
            self::Expired => 'Expired',
            self::Damaged => 'Damaged',
            self::Overproduction => 'Overproduction',
            self::Spoiled => 'Spoiled',
            self::Sample => 'Sample',
        };
    }
}
