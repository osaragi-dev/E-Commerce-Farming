<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasLabel
{
    case Regular = 'regular';
    case Invoice = 'invoice';

    public function getLabel(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Invoice => 'Invoice',
        };
    }
}
