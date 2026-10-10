<?php

namespace App\Filament\Resources\ApprovalSettings\Schemas;

use App\Enums\ApprovalModule;
use App\Models\ApprovalSettingLevel;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApprovalSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Approval Configuration')
                    ->schema([
                        Select::make('module_code')
                            ->label('Module')
                            ->options(ApprovalModule::options())
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Section::make('Approval Levels')
                    ->description('Assign a different role to each level in this approval flow.')
                    ->columns(['default' => 1, 'md' => 12])
                    ->schema([
                        Repeater::make('levels')
                            ->relationship()
                            ->maxItems(ApprovalSettingLevel::MAX_LEVELS)
                            ->helperText('Maximum 3 levels. Each role can be selected only once.')
                            ->schema([
                                Select::make('role_id')
                                    ->label('Approver Role')
                                    ->relationship('role', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->required()
                                    ->columnSpanFull(),
                            ])
                            ->columns(1)
                            ->defaultItems(1)
                            ->addActionLabel('Add Approval Level')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
