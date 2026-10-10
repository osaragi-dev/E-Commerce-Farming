<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Filament\Resources\StockMovements\Support\StockMovementDetailPresenter;
use App\Models\StockMovement;
use App\Models\StockMovementItem;
use App\Models\StockMovementLog;
use App\Support\Quantity;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockMovementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Progress')
                    ->schema([
                        ViewEntry::make('progress')
                            ->hiddenLabel()
                            ->view(
                                'filament.stock-movements.progress',
                                fn (StockMovement $record): array => [
                                    'steps' => app(StockMovementDetailPresenter::class)->timeline($record),
                                ],
                            )
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Movement Information')
                    ->schema([
                        TextEntry::make('movement_number')->label('Movement Number'),
                        TextEntry::make('movement_date')->label('Movement Date')->date(),
                        TextEntry::make('fromWarehouse.name')->label('From Location'),
                        TextEntry::make('toWarehouse.name')->label('To Location'),
                        TextEntry::make('requestedBy.name')->label('Requested By')->placeholder('System'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->state(fn (StockMovement $record): string => $record->trashed()
                                ? 'Deleted'
                                : ($record->status === StockMovementStatus::Draft ? 'Draft' : 'Saved'))
                            ->badge()
                            ->color(fn (StockMovement $record): string => $record->trashed()
                                ? 'danger'
                                : (in_array($record->status, [StockMovementStatus::Draft, StockMovementStatus::Cancelled], true)
                                    ? 'gray'
                                    : 'success')),
                        TextEntry::make('approval_status')
                            ->label('Approval Status')
                            ->state(fn (StockMovement $record): string => self::approvalLabel($record))
                            ->badge()
                            ->color(fn (StockMovement $record): string => $record->status === StockMovementStatus::Draft
                                ? 'gray'
                                : ($record->approval_status?->getColor() ?? 'gray')),
                        TextEntry::make('shipped_at')->label('Shipped At')->dateTime()->placeholder('-'),
                        TextEntry::make('received_at')->label('Received At')->dateTime()->placeholder('-'),
                    ])
                    ->columns(3),
                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->hiddenLabel()
                            ->state(fn (StockMovement $record): ?string => $record->notes ?? $record->note)
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->table([
                                TableColumn::make('Item'),
                                TableColumn::make('Qty Request'),
                                TableColumn::make('Qty Shipped'),
                                TableColumn::make('Qty Receive'),
                                TableColumn::make('Unit'),
                            ])
                            ->schema([
                                TextEntry::make('item')
                                    ->label('Item')
                                    ->state(fn (StockMovementItem $record): string => sprintf(
                                        '%s - %s',
                                        $record->productVariant?->product?->name ?? 'Unknown Product',
                                        $record->productVariant?->name ?? 'Unknown Variant',
                                    )),
                                TextEntry::make('quantity')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Qty Request'),
                                TextEntry::make('quantity_shipped')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Qty Shipped')->placeholder('-'),
                                TextEntry::make('quantity_received')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Qty Receive')->placeholder('-'),
                                TextEntry::make('unit')->label('Unit')->placeholder('-'),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Detail Log')
                    ->schema([
                        RepeatableEntry::make('logs')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Timestamp'),
                                TableColumn::make('Action'),
                                TableColumn::make('PIC'),
                                TableColumn::make('Status Transition'),
                                TableColumn::make('Comment'),
                            ])
                            ->schema([
                                TextEntry::make('created_at')->label('Timestamp')->dateTime(),
                                TextEntry::make('action')->label('Action')->badge(),
                                TextEntry::make('changedBy.name')->label('PIC')->placeholder('System'),
                                TextEntry::make('status_transition')
                                    ->label('Status Transition')
                                    ->state(fn (StockMovementLog $record): string => self::statusTransition($record)),
                                TextEntry::make('notes')->label('Comment')->placeholder('-')->wrap(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function statusTransition(StockMovementLog $log): string
    {
        $from = self::statusLabel($log->from_status);
        $to = self::statusLabel($log->to_status);

        return "{$from} -> {$to}";
    }

    private static function statusLabel(?string $status): string
    {
        return filled($status)
            ? (StockMovementStatus::tryFrom($status)?->getLabel() ?? str($status)->headline())
            : '-';
    }

    private static function approvalLabel(StockMovement $record): string
    {
        if ($record->approval_status === StockMovementApprovalStatus::Rejected) {
            return $record->approval_status->getLabel();
        }

        if (in_array($record->status, [StockMovementStatus::Shipped, StockMovementStatus::Received, StockMovementStatus::Cancelled], true)) {
            return $record->status->getLabel();
        }

        if ($record->approval_status?->value !== 'pending') {
            return $record->approval_status?->getLabel() ?? '-';
        }

        return $record->approval_level > 1
            ? 'L'.($record->approval_level - 1).' Approved'
            : 'Pending';
    }
}
