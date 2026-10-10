<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category')
                    ->schema([
                        TextEntry::make('sector.name')->label('Sector'),
                        TextEntry::make('name'),
                        TextEntry::make('slug'),
                        TextEntry::make('sort_order'),
                        IconEntry::make('is_active')->boolean(),
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
