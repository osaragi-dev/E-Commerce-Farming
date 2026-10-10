<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ItemType: string implements HasLabel
{
    case RawMaterial = 'raw_material';
    case FinishedGood = 'finished_good';
    case SemiFinished = 'semi_finished';
    case Packaging = 'packaging';
    case Asset = 'asset';
    case Energy = 'energy';
    case Sanitary = 'sanitary';

    public function getLabel(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material',
            self::FinishedGood => 'Finished Good',
            self::SemiFinished => 'Semi Finished',
            self::Packaging => 'Packaging',
            self::Asset => 'Asset',
            self::Energy => 'Energy',
            self::Sanitary => 'Sanitary',
        };
    }
}
