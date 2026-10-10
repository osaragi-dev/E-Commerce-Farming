<?php

namespace App\Filament\Resources\CompanyProfile\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Company Information')
                    ->schema([
                        TextInput::make('company_name')
                            ->label('Company Name')
                            ->required()
                            ->maxLength(255),
                        FileUpload::make('logo')
                            ->label('Company Logo')
                            ->image()
                            ->disk('public')
                            ->directory('company-profile')
                            ->helperText('Displayed on the emblem on the About Us page.'),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('Contact & Address')
                    ->schema([
                        Textarea::make('address')
                            ->label('Address')
                            ->rows(3)
                            ->columnSpanFull(),
                        Grid::make(3)
                            ->schema([
                                TextInput::make('whatsapp_number')
                                    ->label('WhatsApp Number')
                                    ->tel()
                                    ->maxLength(30),
                                TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->maxLength(255),
                                TextInput::make('instagram')
                                    ->label('Instagram')
                                    ->prefix('@')
                                    ->maxLength(255)
                                    ->helperText('Username only, without "@" or a URL. Example: farming.official'),
                            ]),
                    ]),
            ]);
    }
}
