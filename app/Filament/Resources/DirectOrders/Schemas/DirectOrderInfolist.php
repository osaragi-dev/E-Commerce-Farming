<?php

namespace App\Filament\Resources\DirectOrders\Schemas;

use App\Filament\Resources\DirectOrders\Support\DirectOrderDetailPresenter;
use App\Filament\Support\Rupiah;
use App\Models\DirectOrder;
use App\Models\DirectOrderItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class DirectOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Progress')->schema([
                ViewEntry::make('progress')
                    ->hiddenLabel()
                    ->view('filament.direct-orders.progress', fn (DirectOrder $record): array => [
                        'steps' => app(DirectOrderDetailPresenter::class)->timeline($record),
                    ])
                    ->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Direct Order Information')->columns(3)->schema([
                TextEntry::make('do_number')->label('DO Number')->placeholder('-'),
                TextEntry::make('do_date')->label('DO Date')->date(),
                TextEntry::make('payment_by')->label('Payment By'),
                TextEntry::make('reason')->label('Reason'),
                TextEntry::make('warehouse.name')->label('Location'),
                TextEntry::make('vendor.name')->label('Vendor'),
                TextEntry::make('requestedBy.name')->label('Requested By')->placeholder('-'),
                TextEntry::make('approval_status')
                    ->label('Approval Status')
                    ->state(fn (DirectOrder $record): string => $record->approvalProgressLabel())
                    ->color(fn (DirectOrder $record): string => $record->approval_status?->getColor() ?? 'gray')
                    ->badge(),
                TextEntry::make('record_status')->label('Status')->badge(),
                TextEntry::make('notes')->columnSpanFull()->placeholder('-'),
            ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->table([
                    TableColumn::make('Item'),
                    TableColumn::make('Unit'),
                    TableColumn::make('Qty'),
                    TableColumn::make('Unit Price'),
                    TableColumn::make('Total Price'),
                    TableColumn::make('Notes'),
                ])->schema([
                    TextEntry::make('item.name')->label('Item')->state(fn (DirectOrderItem $record): string => $record->item
                        ? trim($record->item->code.' - '.$record->item->name)
                        : '-'),
                    TextEntry::make('item.unit.code')->label('Unit')->placeholder('-'),
                    TextEntry::make('quantity')->label('Qty'),
                    TextEntry::make('unit_price')->label('Unit Price')->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                    TextEntry::make('total_price')->label('Total Price')->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                    TextEntry::make('notes')->placeholder('-'),
                ])->columnSpanFull(),
            ]),
            Section::make('Amounts & Terms')->columns(2)->schema([
                TextEntry::make('subtotal')->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                TextEntry::make('tax_amount')->label('Tax Amount')->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                TextEntry::make('discount_amount')->label('Discount Amount')->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                TextEntry::make('total_amount')->label('Total Amount')->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                TextEntry::make('terms_conditions')->label('Terms & Conditions')->columnSpanFull()->placeholder('-'),
                TextEntry::make('transaction_proof')
                    ->label('Transaction Proof')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? basename($state) : '-')
                    ->url(fn (DirectOrder $record): ?string => filled($record->transaction_proof)
                        ? Storage::disk('public')->url($record->transaction_proof)
                        : null)
                    ->openUrlInNewTab()
                    ->columnSpanFull(),
            ]),
            Section::make('Detail Log')->id('detail-log')->schema([
                RepeatableEntry::make('approvalLogs')->hiddenLabel()->table([
                    TableColumn::make('Timestamp'),
                    TableColumn::make('Action'),
                    TableColumn::make('PIC'),
                    TableColumn::make('Status'),
                    TableColumn::make('Comment'),
                ])->schema([
                    TextEntry::make('created_at')->label('Timestamp')->dateTime(),
                    TextEntry::make('action')->badge(),
                    TextEntry::make('actedBy.name')->label('PIC')->placeholder('System'),
                    TextEntry::make('action')->label('Status')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                    TextEntry::make('notes')->label('Comment')->placeholder('-')->wrap(),
                ])->columnSpanFull(),
            ]),
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
