<?php

namespace App\Filament\Resources\ProductDisplaySetting\Schemas;

use App\Models\ProductDisplaySetting;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductDisplaySettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Newest Products')
                    ->schema([
                        TextInput::make('new_badge_days')
                            ->label('Show newest products for (days)')
                            ->helperText('Products created or activated within this period appear in the Newest Products section on the homepage. The New badge uses the same period.')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->default(14)
                            ->required(),
                    ]),
                Section::make('Audit')
                    ->schema([
                        Placeholder::make('last_updated_at')
                            ->label('Last Updated At')
                            ->content(fn (?ProductDisplaySetting $record): string => $record?->updated_at?->format('Y-m-d H:i:s') ?? '-'),
                        Placeholder::make('last_updated_by')
                            ->label('Last Updated By')
                            ->content(fn (?ProductDisplaySetting $record): string => $record?->updatedBy?->name ?? '-'),
                    ])
                    ->columns(2)
                    ->collapsed(false),
            ]);
    }
}
