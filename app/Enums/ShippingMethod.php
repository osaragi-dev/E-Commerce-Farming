<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ShippingMethod: string implements HasLabel
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';

    public function getLabel(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery',
            self::Pickup => 'Pickup',
        };
    }

    public function getLocationLabel(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery Address',
            self::Pickup => 'Pickup Location',
        };
    }
}
