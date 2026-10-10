<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ShippingLocation: string implements HasLabel
{
    case Sccr = 'SCCR';
    case AgungPutraUniversity = 'Agung Putra University';
    case SainsDeResto = 'Sains De Resto';
    case KareninaAgungResort = 'Karenina Agung Resort';
    case AgungFarmAndGarden = 'Agung Farm & Garden';

    public function getLabel(): string
    {
        return $this->value;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $location) {
            $options[$location->value] = $location->getLabel();
        }

        return $options;
    }
}
