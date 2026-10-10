<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockWasteSource: string implements HasLabel
{
    case Item = 'item';
    case Menu = 'menu';

    public function getLabel(): string
    {
        return match ($this) {
            self::Item => 'Item',
            self::Menu => 'Menu',
        };
    }
}
