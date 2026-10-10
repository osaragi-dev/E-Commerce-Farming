<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RecordStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Saved = 'saved';
    case Deleted = 'deleted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Saved => 'Saved',
            self::Deleted => 'Deleted',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Saved => 'success',
            self::Deleted => 'danger',
        };
    }
}
