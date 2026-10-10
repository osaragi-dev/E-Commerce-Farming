<?php

namespace App\Filament\Resources\StockAdjustments\Schemas;

use App\Filament\Resources\StockAdjustments\Support\StockAdjustmentDetailPresenter;
use App\Models\StockAdjustment;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockAdjustmentInfolist
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
                                'filament.stock-adjustments.progress',
                                fn (StockAdjustment $record): array => [
                                    'steps' => app(StockAdjustmentDetailPresenter::class)->timeline($record),
                                ],
                            )
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Adjustment Information')
                    ->schema([
                        TextEntry::make('label')->label('Adjustment Reference'),
                        TextEntry::make('adjustment_number')->label('Adjustment Number')->placeholder('-'),
                        TextEntry::make('source')
                            ->label('Source')
                            ->formatStateUsing(fn ($state): string => $state?->getLabel() ?? '-')
                            ->badge()
                            ->color('success'),
                        TextEntry::make('created_at')->label('Adjustment Date')->date(),
                        TextEntry::make('warehouse.name')->label('Location'),
                        TextEntry::make('record_status')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('approval_status')
                            ->label('Approval Status')
                            ->state(fn (StockAdjustment $record): string => $record->approvalStatusLabel())
                            ->badge()
                            ->color(fn (StockAdjustment $record): string => $record->approval_status?->getColor() ?? 'gray'),
                        TextEntry::make('stock_opname_number')
                            ->label('Stock Opname')
                            ->state(fn (StockAdjustment $record): string => app(StockAdjustmentDetailPresenter::class)->stockOpnameNumber($record))
                            ->hidden(fn (StockAdjustment $record): bool => $record->stockOpnameItems->isEmpty()),
                    ])
                    ->columns(3),
                Section::make('Items')
                    ->schema([
                        ViewEntry::make('items')
                            ->hiddenLabel()
                            ->view('filament.stock-adjustments.items-table', fn (StockAdjustment $record): array => [
                                'items' => app(StockAdjustmentDetailPresenter::class)->items($record),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Reason & Notes')
                    ->schema([
                        TextEntry::make('reason')
                            ->label('Reason')
                            ->state(fn (StockAdjustment $record): string => $record->reason?->getLabel() ?? '-'),
                        TextEntry::make('note')
                            ->label('Notes')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Detail Log')
                    ->schema([
                        ViewEntry::make('activities')
                            ->hiddenLabel()
                            ->view('filament.stock-adjustments.activity-log', fn (StockAdjustment $record): array => [
                                'activities' => app(StockAdjustmentDetailPresenter::class)->activities($record),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
