<?php

namespace App\Filament\Resources\StockOpnames\Schemas;

use App\Enums\StockOpnameStatus;
use App\Filament\Resources\StockOpnames\Support\StockOpnameDetailPresenter;
use App\Models\ApprovalLog;
use App\Models\StockOpnameLog;
use App\Models\StockOpnameSession;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class StockOpnameInfolist
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
                            ->view('filament.stock-opnames.progress', fn (StockOpnameSession $record): array => [
                                'steps' => app(StockOpnameDetailPresenter::class)->timeline($record),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Opname Information')
                    ->schema([
                        TextEntry::make('opname_number')->label('Opname Number'),
                        TextEntry::make('period')->label('Period'),
                        TextEntry::make('opname_date')->label('Opname Date')->date(),
                        TextEntry::make('warehouse.name')->label('Location'),
                        TextEntry::make('checker.name')->label('Checker')->placeholder('-'),
                        TextEntry::make('witness.name')->label('Witness')->placeholder('-'),
                        TextEntry::make('status')
                            ->label('Workflow Status')
                            ->state(fn (StockOpnameSession $record): string => $record->trashed()
                                ? 'Deleted'
                                : ($record->status?->value === 'draft' ? 'Draft' : 'Saved'))
                            ->badge(),
                        TextEntry::make('approval_status')
                            ->label('Approval Status')
                            ->state(fn (StockOpnameSession $record): string => self::approvalLabel($record))
                            ->badge(),
                        TextEntry::make('finalized')
                            ->label('Finalized')
                            ->state(fn (StockOpnameSession $record): string => $record->status === StockOpnameStatus::Finalized ? 'Yes' : 'No')
                            ->badge()
                            ->color(fn (StockOpnameSession $record): string => $record->status === StockOpnameStatus::Finalized ? 'success' : 'gray'),
                        TextEntry::make('finalized_at')->label('Finalized At')->dateTime()->placeholder('-'),
                        TextEntry::make('created_at')->label('Start Time')->dateTime(),
                        TextEntry::make('end_time')
                            ->label('End Time')
                            ->state(fn (StockOpnameSession $record) => self::endTime($record))
                            ->dateTime()
                            ->placeholder('-'),
                    ])
                    ->columns(3),
                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')->hiddenLabel()->placeholder('-')->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Detail Log')
                    ->schema([
                        ViewEntry::make('activity_log')
                            ->hiddenLabel()
                            ->view('filament.stock-opnames.activity-log', fn (StockOpnameSession $record): array => [
                                'activities' => app(StockOpnameDetailPresenter::class)->activities($record),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function approvalLabel(StockOpnameSession $record): string
    {
        if ($record->approval_status?->value !== 'pending') {
            return $record->approval_status?->getLabel() ?? '-';
        }

        return $record->approval_level > 1
            ? 'L'.($record->approval_level - 1).' Approved'
            : 'Pending';
    }

    private static function endTime(StockOpnameSession $record): ?Carbon
    {
        if ($record->approved_at !== null) {
            return $record->approved_at;
        }

        $lastApproval = $record->approvalLogs
            ->filter(fn (ApprovalLog $log): bool => $log->action === 'approved')
            ->sortByDesc('created_at')
            ->first();

        if ($lastApproval?->created_at !== null) {
            return $lastApproval->created_at;
        }

        return $record->logs
            ->first(fn (StockOpnameLog $log): bool => $log->to_status === StockOpnameStatus::Submitted->value)
            ?->created_at;
    }
}
