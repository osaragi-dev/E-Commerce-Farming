<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Support\Rupiah;
use Filament\Tables\Columns\TextColumn;

class RupiahColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state));
    }
}
