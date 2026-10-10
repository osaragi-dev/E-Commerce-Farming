<?php

namespace App\Filament\Resources\Units\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Unique among saved records. Drafts may reuse a code.'),
                TextInput::make('name')
                    ->label('Unit Name')
                    ->required()
                    ->maxLength(100),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
