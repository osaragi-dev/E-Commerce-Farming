<?php

namespace App\Filament\Resources\StockWastes\Schemas;

use App\Models\StockWaste;
use App\Support\Quantity;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockWasteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Waste Record')->columns(3)->schema([
                TextEntry::make('waste_number')->label('Waste Number')->copyable(),
                TextEntry::make('source')->label('Source')->badge(),
                TextEntry::make('warehouse.name')->label('Location'),
                TextEntry::make('waste_date')->label('Date')->date(),
                TextEntry::make('picUser.name')->label('PIC User'),
                TextEntry::make('productVariant.product.name')->label('Product'),
                TextEntry::make('productVariant.name')->label('Item'),
                TextEntry::make('productVariant.unit.name')->label('Unit'),
                TextEntry::make('quantity')->formatStateUsing(fn ($state): string => Quantity::format($state)),
                TextEntry::make('waste_type')->label('Waste Type')->badge(),
                TextEntry::make('waste_status')->label('Status')->badge(),
                TextEntry::make('reason')->placeholder('-')->columnSpanFull(),
                ImageEntry::make('photo_path')->label('Photo')->disk('public')->size(320)
                    ->visible(fn (StockWaste $record): bool => filled($record->photo_path)),
                TextEntry::make('notes')->label('Notes')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Posting')->columns(3)->schema([
                TextEntry::make('posted_at')->dateTime()->placeholder('Not posted'),
                TextEntry::make('postedBy.name')->label('Posted By')->placeholder('-'),
                TextEntry::make('stockMutation.quantity_change')->label('Stock Change')
                    ->formatStateUsing(fn ($state): string => $state === null ? '-' : Quantity::format($state)),
            ]),
            Section::make('Audit')->columns(4)->schema([
                TextEntry::make('createdBy.name')->label('Created By'),
                TextEntry::make('created_at')->label('Created At')->dateTime(),
                TextEntry::make('updatedBy.name')->label('Updated By')->placeholder('-'),
                TextEntry::make('updated_at')->label('Updated At')->dateTime(),
            ]),
        ]);
    }
}
