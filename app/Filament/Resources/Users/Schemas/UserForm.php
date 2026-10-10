<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(255)
                ->dehydrateStateUsing(fn (string $state): string => Str::lower(trim($state)))
                ->unique(ignoreRecord: true),
            TextInput::make('password')
                ->label('Temporary Password')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->minLength(8)
                ->required()
                ->visibleOn('create')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->required(),
        ]);
    }
}
