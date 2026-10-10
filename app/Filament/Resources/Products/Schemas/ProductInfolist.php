<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Support\Rupiah;
use App\Support\Quantity;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Product')
                    ->schema([
                        TextEntry::make('category.sector.name')->label('Sector'),
                        TextEntry::make('category.name')->label('Category'),
                        TextEntry::make('name'),
                        TextEntry::make('slug'),
                        IconEntry::make('is_active')->boolean(),
                        TextEntry::make('description')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Variants')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('variants')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Variant'),
                                TableColumn::make('Unit'),
                                TableColumn::make('Price Quantity'),
                                TableColumn::make('Price'),
                                TableColumn::make('Initial Stock'),
                                TableColumn::make('Min. Stock'),
                                TableColumn::make('Status'),
                                TableColumn::make('Active'),
                            ])
                            ->schema([
                                TextEntry::make('name')->label('Variant'),
                                TextEntry::make('unit.name')->label('Unit'),
                                TextEntry::make('price_quantity')->formatStateUsing(fn ($state): string => Quantity::format($state)),
                                TextEntry::make('price')->formatStateUsing(fn ($state) => Rupiah::formatWithPrefix($state)),
                                TextEntry::make('initial_stock')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Initial Stock'),
                                TextEntry::make('low_stock_threshold')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Min. Stock'),
                                TextEntry::make('stockStatus')->label('Status')->badge(),
                                IconEntry::make('is_active')->label('Active')->boolean(),
                            ]),
                    ]),
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
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
