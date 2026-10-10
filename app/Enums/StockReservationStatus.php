<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockReservationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Released = 'released';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Reserved => 'Reserved',
            self::Released => 'Released',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Reserved => 'success',
            self::Released => 'gray',
        };
    }
}
