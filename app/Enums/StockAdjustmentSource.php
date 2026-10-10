<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockAdjustmentSource: string implements HasLabel
{
    case DirectAdjustment = 'direct_adjustment';
    case StockOpname = 'stock_opname';

    public function getLabel(): string
    {
        return match ($this) {
            self::DirectAdjustment => 'Direct Adjustment',
            self::StockOpname => 'From Stock Opname',
        };
    }
}
