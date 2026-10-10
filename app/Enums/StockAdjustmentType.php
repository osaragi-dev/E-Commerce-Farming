<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockAdjustmentType: string implements HasColor, HasLabel
{
    case Tambah = 'tambah';
    case Kurang = 'kurang';

    public function getLabel(): string
    {
        return match ($this) {
            self::Tambah => 'Add',
            self::Kurang => 'Reduce',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Tambah => 'success',
            self::Kurang => 'danger',
        };
    }
}
