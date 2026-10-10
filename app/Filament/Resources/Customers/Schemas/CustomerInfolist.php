<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Customer Data')
                    ->schema([
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('customer_type')->label('Customer Type'),
                        TextEntry::make('email')->placeholder('-'),
                        TextEntry::make('phone')->label('Phone'),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        TextEntry::make('address')->label('Address')->placeholder('-'),
                        // QA revision (28 Aug): "Terdaftar Sejak" (created_at)
                        // dihapus dari sini sesuai request Nabila.
                    ])
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->columnSpanFull(),
                Section::make('Audit')
                    ->schema([
                        TextEntry::make('deletedBy.name')->label('Deleted By')->placeholder('-'),
                        TextEntry::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-'),
                    ])
                    ->columns(2)
                    ->collapsed(false),
            ]);
    }
}
