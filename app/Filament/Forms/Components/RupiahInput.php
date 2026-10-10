<?php

namespace App\Filament\Forms\Components;

use App\Filament\Support\Rupiah;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

class RupiahInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->prefix('Rp')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.', 2)
                JS))
            ->inputMode('decimal')
            ->rule('numeric')
            ->formatStateUsing(fn (mixed $state): ?string => Rupiah::format($state))
            ->dehydrateStateUsing(fn (mixed $state): ?string => Rupiah::normalize($state))
            ->mutateStateForValidationUsing(fn (mixed $state): ?string => Rupiah::normalize($state));
    }
}
