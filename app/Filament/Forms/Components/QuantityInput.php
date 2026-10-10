<?php

namespace App\Filament\Forms\Components;

use App\Support\Quantity;
use Filament\Forms\Components\TextInput;

class QuantityInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->inputMode('decimal')
            ->rules(['numeric', 'regex:/^-?\d+(?:\.\d{1,3})?$/D', 'max:999999999999999.999'])
            ->formatStateUsing(fn (mixed $state): ?string => filled($state) ? Quantity::format($state) : null)
            ->dehydrateStateUsing(fn (mixed $state): mixed => Quantity::normalize($state))
            ->mutateStateForValidationUsing(fn (mixed $state): mixed => Quantity::normalize($state));
    }
}
