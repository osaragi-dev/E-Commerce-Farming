<?php

namespace App\Filament\Resources\DirectOrders\Tables;

use App\Enums\RecordStatus;
use App\Filament\Resources\DirectOrders\DirectOrderResource;
use App\Filament\Tables\ApplicationTable;
use App\Filament\Tables\Columns\RupiahColumn;
use App\Models\DirectOrder;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class DirectOrdersTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'warehouse', 'vendor', 'createdBy', 'updatedBy', 'deletedBy', 'approvalSetting.levels',
            ])->withCount('approvalLogs'))
            ->columns([
                TextColumn::make('approval_status')
                    ->label('Approval')
                    ->state(fn (DirectOrder $record): string => $record->approvalProgressLabel())
                    ->badge()
                    ->color(fn (DirectOrder $record): string => $record->approval_status?->getColor() ?? 'gray'),
                TextColumn::make('progress')->label('Progress'),
                TextColumn::make('do_number')->label('DO Number')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('do_date')->label('DO Date')->date()->sortable(),
                TextColumn::make('warehouse.name')->label('Location')->searchable()->sortable(),
                TextColumn::make('vendor.name')->label('Vendor')->searchable()->sortable(),
                RupiahColumn::make('total_amount')->label('Total Amount')->sortable(),
                TextColumn::make('record_status')->label('Status')->badge()->sortable(),
                TextColumn::make('approval_logs_count')
                    ->label('Log History')
                    ->counts('approvalLogs')
                    ->sortable()
                    ->formatStateUsing(fn (): string => 'log history')
                    ->color('success')
                    ->url(fn (DirectOrder $record): string => DirectOrderResource::getUrl('view', ['record' => $record]).'#detail-log'),
                TextColumn::make('notes')->limit(40)->placeholder('-'),
                TextColumn::make('transaction_proof')
                    ->label('Proof')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? '1' : '-')
                    ->icon(fn (?string $state): ?Heroicon => filled($state) ? Heroicon::OutlinedLink : null)
                    ->iconColor('gray')
                    ->color('success')
                    ->tooltip(fn (DirectOrder $record): ?string => filled($record->transaction_proof) ? 'Open transaction proof' : null)
                    ->url(fn (DirectOrder $record): ?string => filled($record->transaction_proof)
                        ? Storage::disk('public')->url($record->transaction_proof)
                        : null)
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('record_status')->label('Status')->options([
                    RecordStatus::Draft->value => 'Draft',
                    RecordStatus::Saved->value => 'Saved',
                    RecordStatus::Deleted->value => 'Deleted',
                ]),
                DateRangeFilter::make('do_date')->label('DO Date'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (DirectOrder $record): string => DirectOrderResource::getUrl('view', ['record' => $record])),
                Action::make('edit')
                    ->label('Edit')
                    ->url(fn (DirectOrder $record): string => DirectOrderResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (DirectOrder $record): bool => DirectOrderResource::canEdit($record)),
                ReplicateAction::make('clone')
                    ->label('Clone')
                    ->hidden(fn (DirectOrder $record): bool => $record->trashed())
                    ->excludeAttributes([
                        'id', 'do_number', 'record_status', 'previous_record_status',
                        'approval_status', 'approval_level', 'approval_setting_id',
                        'created_by', 'updated_by', 'deleted_by', 'created_at', 'updated_at', 'deleted_at',
                        'approval_logs_count',
                    ])
                    ->databaseTransaction()
                    ->after(function (DirectOrder $record, DirectOrder $replica): void {
                        $record->items()->orderBy('sort_order')->get()->each(function ($item) use ($replica): void {
                            $replica->items()->create([
                                'item_id' => $item->item_id,
                                'quantity' => $item->quantity,
                                'unit_price' => $item->unit_price,
                                'total_price' => $item->total_price,
                                'notes' => $item->notes,
                                'sort_order' => $item->sort_order,
                            ]);
                        });
                    }),
                DeleteAction::make()->visible(fn (DirectOrder $record): bool => DirectOrderResource::canDelete($record)),
                RestoreAction::make()->visible(fn (DirectOrder $record): bool => DirectOrderResource::canRestore($record)),
            ])
            ->recordActionsColumnLabel('Action')
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                ]),
            ]);

        $table = ApplicationTable::configure(
            $table,
            DirectOrderResource::class,
            statusLabel: 'Status',
            rowNumberLabel: 'No',
        );

        foreach (['createdBy.name', 'created_at', 'updatedBy.name', 'updated_at'] as $columnName) {
            $table->getColumn($columnName)->toggleable(isToggledHiddenByDefault: false);
        }

        return $table;
    }
}
