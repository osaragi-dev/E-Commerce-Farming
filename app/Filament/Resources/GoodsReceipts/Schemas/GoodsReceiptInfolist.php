<?php

namespace App\Filament\Resources\GoodsReceipts\Schemas;

use App\Models\GoodsReceipt;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class GoodsReceiptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Goods Receipt Information')->columns(3)->schema([
                TextEntry::make('receipt_number')->label('GR Number'),
                TextEntry::make('received_at')->label('Received Date')->dateTime('d M Y H:i'),
                TextEntry::make('purchaseOrder.po_number')->label('PO Number'),
                TextEntry::make('purchaseOrder.warehouse.name')->label('Location'),
                TextEntry::make('purchaseOrder.vendor.name')->label('Vendor'),
                TextEntry::make('delivery_note_number')->label('Delivery Note')->placeholder('-'),
                TextEntry::make('checkedBy.name')->label('Checked By'),
                TextEntry::make('record_status')->label('Status')->badge(),
                TextEntry::make('approval_status')->label('Approval Status')->badge(),
                TextEntry::make('documentation_path')->label('Documentation')->url(fn (GoodsReceipt $record): ?string => $record->documentation_path ? Storage::disk('public')->url($record->documentation_path) : null)->openUrlInNewTab(),
                TextEntry::make('notes')->label('Notes')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->table([
                    TableColumn::make('Item'),
                    TableColumn::make('Ordered'),
                    TableColumn::make('Receive Qty'),
                    TableColumn::make('Accepted'),
                    TableColumn::make('Rejected'),
                    TableColumn::make('Rejection Reason'),
                    TableColumn::make('Condition'),
                    TableColumn::make('Unit'),
                    TableColumn::make('Notes'),
                ])->schema([
                    TextEntry::make('purchaseOrderItem.item.name')->label('Item'),
                    TextEntry::make('purchaseOrderItem.qty_order')->label('Ordered'),
                    TextEntry::make('quantity_received')->label('Receive Qty'),
                    TextEntry::make('accepted_quantity')->label('Accepted'),
                    TextEntry::make('rejected_quantity')->label('Rejected'),
                    TextEntry::make('rejection_reason')->label('Rejection Reason')->placeholder('-'),
                    TextEntry::make('condition')->label('Condition')->formatStateUsing(fn (?string $state): string => $state ? str($state)->headline()->toString() : '-'),
                    TextEntry::make('purchaseOrderItem.unit_code')->label('Unit'),
                    TextEntry::make('notes')->label('Notes')->placeholder('-'),
                ]),
            ]),
            Section::make('Approval History')->schema([
                RepeatableEntry::make('approvalLogs')->hiddenLabel()->table([
                    TableColumn::make('Date'), TableColumn::make('Action'), TableColumn::make('PIC'), TableColumn::make('Notes'),
                ])->schema([
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('action')->badge(),
                    TextEntry::make('actedBy.name')->placeholder('System'),
                    TextEntry::make('notes')->placeholder('-'),
                ]),
            ]),
        ]);
    }
}
