<?php

namespace App\Filament\Resources\PurchaseRequests\Schemas;

use App\Filament\Resources\PurchaseRequests\Support\PurchaseRequestDetailPresenter;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Progress')->schema([
                ViewEntry::make('progress')
                    ->hiddenLabel()
                    ->view('filament.purchase-requests.progress', fn (PurchaseRequest $record): array => [
                        'steps' => app(PurchaseRequestDetailPresenter::class)->timeline($record),
                    ])
                    ->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Purchase Request Information')->columns(3)->schema([
                TextEntry::make('pr_number')->label('PR Number')->placeholder('-'),
                TextEntry::make('pr_date')->label('PR Date')->date(),
                TextEntry::make('warehouse.name')->label('Location'),
                TextEntry::make('requestedBy.name')->label('Requested By')->placeholder('-'),
                TextEntry::make('required_date')->label('Required Date')->date(),
                TextEntry::make('record_status')->label('Status')->badge(),
                TextEntry::make('approval_status')
                    ->label('Approval Status')
                    ->state(fn (PurchaseRequest $record): string => $record->approvalProgressLabel())
                    ->badge(),
                TextEntry::make('notes')->label('Notes')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Items Checklist')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->table([
                    TableColumn::make('Item'),
                    TableColumn::make('Qty Order'),
                    TableColumn::make('Specification'),
                    TableColumn::make('Notes'),
                ])->schema([
                    TextEntry::make('item.name')->label('Item')->state(fn (PurchaseRequestItem $record): string => $record->item?->name ?? '-'),
                    TextEntry::make('qty_order')->label('Qty Order')->numeric(decimalPlaces: 2),
                    TextEntry::make('specification')->placeholder('-'),
                    TextEntry::make('notes')->placeholder('-'),
                ])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('Detail Log')->schema([
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
            ])->columnSpanFull(),
            Section::make('Audit')->columns(4)->collapsed()->schema([
                TextEntry::make('created_at')->label('Created At')->dateTime(),
                TextEntry::make('createdBy.name')->label('Created By')->placeholder('-'),
                TextEntry::make('updated_at')->label('Updated At')->dateTime(),
                TextEntry::make('updatedBy.name')->label('Updated By')->placeholder('-'),
                TextEntry::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-'),
                TextEntry::make('deletedBy.name')->label('Deleted By')->placeholder('-'),
            ]),
        ]);
    }
}
