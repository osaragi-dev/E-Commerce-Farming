<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Filament\Resources\PurchaseOrders\Support\PurchaseOrderDetailPresenter;
use App\Filament\Support\Rupiah;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Approval Timeline')->schema([
                ViewEntry::make('approval_progress')->hiddenLabel()
                    ->view('filament.purchase-orders.progress', fn (PurchaseOrder $record): array => [
                        'timelines' => app(PurchaseOrderDetailPresenter::class)->timelines($record),
                    ])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Purchase Order Information')->columns(4)->schema([
                TextEntry::make('po_number')->label('PO Number')->placeholder('-'),
                TextEntry::make('po_date')->label('PO Date')->date(),
                TextEntry::make('expected_delivery_date')->label('Expected Delivery')->date()->placeholder('-'),
                TextEntry::make('purchaseRequest.pr_number')->label('PR Number'),
                TextEntry::make('record_status')->label('Status')->badge(),
                TextEntry::make('approval_status')->label('Approval Status')->badge(),
                TextEntry::make('received_status')->label('Received Status')->badge()->formatStateUsing(fn ($state): string => $state?->getLabel() ?? '-'),
                TextEntry::make('closing_status')->label('Closing Status')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextEntry::make('warehouse.name')->label('Location'),
                TextEntry::make('vendor.name')->label('Vendor'),
                TextEntry::make('payment_by')->label('Payment By')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
            ]),
            Section::make('Items')->schema([
                ViewEntry::make('items')->hiddenLabel()->view('filament.purchase-orders.items', fn (PurchaseOrder $record): array => [
                    'items' => $record->items,
                ])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Amounts & Terms')->columns(2)->schema([
                TextEntry::make('subtotal')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                TextEntry::make('tax_amount')->label('Tax Amount')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                TextEntry::make('discount_amount')->label('Discount Amount')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                TextEntry::make('total_amount')->label('Total Amount')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                TextEntry::make('terms_and_conditions')->label('Terms & Conditions')->placeholder('-')->columnSpanFull(),
                TextEntry::make('notes')->label('Notes')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Payment')->columns(3)->schema([
                TextEntry::make('payment_status')->label('Payment Status')->badge()->formatStateUsing(fn ($state): string => $state?->getLabel() ?? '-'),
                TextEntry::make('payment_basis')->label('Payment Basis')->formatStateUsing(fn (string $state): string => match ($state) {
                    'goods_receipt' => 'Goods Receipt',
                    'purchase_order' => 'Purchase Order',
                    default => str($state)->headline()->toString(),
                }),
                TextEntry::make('accepted_payable_amount')->label('Accepted Amount')->state(fn (PurchaseOrder $record): string => Rupiah::formatWithPrefix($record->acceptedAmountForBasis()) ?? 'Rp 0,00'),
                TextEntry::make('tax_amount')->label('Tax')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                TextEntry::make('discount_amount')->label('Discount')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                TextEntry::make('payable_amount')->label('Total Payable')->state(fn (PurchaseOrder $record): string => Rupiah::formatWithPrefix($record->payableAmount()) ?? 'Rp 0,00'),
                TextEntry::make('payment_total')->label('Paid')->state(fn (PurchaseOrder $record): string => Rupiah::formatWithPrefix($record->payment_total) ?? 'Rp 0,00'),
                TextEntry::make('remaining_amount')->label('Remaining')->state(fn (PurchaseOrder $record): string => Rupiah::formatWithPrefix($record->remainingAmount()) ?? 'Rp 0,00'),
                RepeatableEntry::make('payments')->label('Termin')->table([
                    TableColumn::make('Termin'),
                    TableColumn::make('Date'),
                    TableColumn::make('Payment'),
                    TableColumn::make('Accepted Amount'),
                    TableColumn::make('Payable'),
                    TableColumn::make('Remaining'),
                    TableColumn::make('Notes'),
                    TableColumn::make('PIC'),
                ])->schema([
                    TextEntry::make('id')->label('Termin')->state(fn (PurchaseOrderPayment $record): string => 'Termin '.($record->purchaseOrder->payments->search(fn ($payment) => $payment->is($record)) + 1)),
                    TextEntry::make('payment_date')->label('Date')->date(),
                    TextEntry::make('payment_amount')->label('Payment')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                    TextEntry::make('accepted_amount')->label('Accepted Amount')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                    TextEntry::make('total_payable')->label('Payable')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                    TextEntry::make('remaining_amount')->label('Remaining')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                    TextEntry::make('notes')->label('Notes')->placeholder('-'),
                    TextEntry::make('createdBy.name')->label('PIC')->placeholder('-'),
                ])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Goods Receipts')->schema([
                RepeatableEntry::make('goodsReceipts')->hiddenLabel()->table([
                    TableColumn::make('GRN Number'), TableColumn::make('Received At'), TableColumn::make('Status'), TableColumn::make('Accepted Qty'), TableColumn::make('Notes'),
                ])->schema([
                    TextEntry::make('receipt_number')->label('GRN Number'),
                    TextEntry::make('received_at')->label('Received At')->date(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('items')->label('Accepted Qty')->state(fn ($record): string => number_format((float) $record->items->sum('accepted_quantity'), 3)),
                    TextEntry::make('notes')->placeholder('-'),
                ])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Purchase Invoices')->schema([
                RepeatableEntry::make('purchaseInvoices')->hiddenLabel()->table([
                    TableColumn::make('Invoice Number'), TableColumn::make('Invoice Date'), TableColumn::make('Status'), TableColumn::make('Grand Total'),
                ])->schema([
                    TextEntry::make('invoice_number')->label('Invoice Number')->placeholder('-'),
                    TextEntry::make('invoice_date')->label('Invoice Date')->date(),
                    TextEntry::make('record_status')->label('Status')->badge(),
                    TextEntry::make('grand_total')->label('Grand Total')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00'),
                ])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Audit')->columns(4)->collapsed()->schema([
                TextEntry::make('createdBy.name')->label('Created By')->placeholder('-'),
                TextEntry::make('created_at')->label('Created At')->dateTime(),
                TextEntry::make('updatedBy.name')->label('Updated By')->placeholder('-'),
                TextEntry::make('updated_at')->label('Updated At')->dateTime(),
                TextEntry::make('deletedBy.name')->label('Deleted By')->placeholder('-'),
                TextEntry::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-'),
            ]),
        ]);
    }
}
