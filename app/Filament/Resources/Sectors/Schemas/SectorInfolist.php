<?php

namespace App\Filament\Resources\Sectors\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SectorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sector')
                    ->schema([
                        ImageEntry::make('image')->disk('public')->square(),
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
