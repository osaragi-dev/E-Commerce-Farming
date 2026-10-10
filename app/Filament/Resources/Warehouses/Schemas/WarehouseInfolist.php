<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WarehouseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Warehouse')->schema([
                TextEntry::make('code'),
                TextEntry::make('name'),
                IconEntry::make('is_active')->boolean(),
                IconEntry::make('is_pos')->label('Is POS')->boolean(),
                TextEntry::make('description')->columnSpanFull(),
            ])->columns(2),
            Section::make('Audit')->schema([
                TextEntry::make('record_status')->badge(),
                TextEntry::make('created_at')->label('Created At')->dateTime(),
                TextEntry::make('createdBy.name')->label('Created By'),
                TextEntry::make('updated_at')->label('Updated At')->dateTime(),
                TextEntry::make('updatedBy.name')->label('Updated By'),
                TextEntry::make('deleted_at')->label('Deleted At')->dateTime(),
                TextEntry::make('deletedBy.name')->label('Deleted By'),
            ])->columns(3)->collapsed(false),
        ]);
    }
}
