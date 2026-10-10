<?php

namespace App\Filament\Resources\Units\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UnitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Unit')
                    ->schema([
                        TextEntry::make('code'),
                        TextEntry::make('name'),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                    ])
                    ->columns(2),
                Section::make('Audit')
                    ->schema([
                        TextEntry::make('record_status')->badge(),
                        TextEntry::make('createdBy.name')->label('Created By'),
                        TextEntry::make('updatedBy.name')->label('Updated By'),
                        TextEntry::make('deletedBy.name')->label('Deleted By'),
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('updated_at')->dateTime(),
                        TextEntry::make('deleted_at')->dateTime(),
                    ])
                    ->columns(3)
                    ->collapsed(false),
            ]);
    }
}
