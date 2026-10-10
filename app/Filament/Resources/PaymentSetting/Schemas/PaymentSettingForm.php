<?php

namespace App\Filament\Resources\PaymentSetting\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentSettingForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(): array
    {
        return [
            Section::make('Payment Proof')
                ->schema([
                    TextInput::make('proof_resubmission_hours')
                        ->label('Payment Proof Upload Window (hours)')
                        ->helperText('Customers must upload initial or replacement payment proof within this period.')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(720)
                        ->default(24)
                        ->required(),
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }
}
