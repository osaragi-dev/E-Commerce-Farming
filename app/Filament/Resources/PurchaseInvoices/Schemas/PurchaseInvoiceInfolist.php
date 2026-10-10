<?php

namespace App\Filament\Resources\PurchaseInvoices\Schemas;

use App\Filament\Support\Rupiah;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Invoice Information')->columns(3)->schema([
                TextEntry::make('invoice_number')->label('Invoice Number')->placeholder('-'),
                TextEntry::make('purchaseOrder.po_number')->label('Purchase Order'),
                TextEntry::make('vendor.name')->label('Vendor'),
                TextEntry::make('invoice_date')->label('Invoice Date')->date(),
                TextEntry::make('record_status')->label('Status')->badge(),
                TextEntry::make('notes')->label('Notes')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->table([
                    TableColumn::make('Item'), TableColumn::make('Invoice Qty'), TableColumn::make('Final Unit Price'), TableColumn::make('Line Total'),
                ])->schema([
                    TextEntry::make('purchaseOrderItem.item.name')->label('Item'),
                    TextEntry::make('invoice_quantity')->label('Invoice Qty'),
                    TextEntry::make('final_unit_price')->label('Final Unit Price')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                    TextEntry::make('line_total')->label('Line Total')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                ])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Amounts & Document')->columns(2)->schema([
                TextEntry::make('subtotal')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                TextEntry::make('tax_amount')->label('Tax Amount')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                TextEntry::make('discount_amount')->label('Discount Amount')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                TextEntry::make('grand_total')->label('Grand Total')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
            ]),
        ]);
    }
}
