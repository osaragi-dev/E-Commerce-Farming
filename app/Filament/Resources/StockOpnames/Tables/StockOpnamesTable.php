<?php

namespace App\Filament\Resources\StockOpnames\Tables;

use App\Enums\StockOpnameApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Tables\ApplicationTable;
use App\Models\StockOpnameSession;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockOpnamesTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'items',
            ]))
            ->columns([
                TextColumn::make('row_number')->label('No')->rowIndex(),
                TextColumn::make('approval_status')
                    ->label('Approval Status')
                    ->state(fn (StockOpnameSession $record): string => self::approvalLabel($record))
                    ->badge()
                    ->color(fn (StockOpnameSession $record): string => $record->status === StockOpnameStatus::Draft
                        ? 'gray'
                        : ($record->approval_status?->getColor() ?? 'gray'))
                    ->sortable(),
                TextColumn::make('opname_number')->label('Opname Number')->placeholder('-')->searchable()->sortable(),
                TextColumn::make('period')->label('Period')->sortable(),
                TextColumn::make('opname_date')->label('Opname Date')->date()->sortable(),
                TextColumn::make('warehouse.name')->label('Warehouse')->searchable()->sortable(),
                TextColumn::make('checker.name')->label('Checker')->placeholder('-')->sortable(),
                TextColumn::make('witness.name')->label('Witness')->placeholder('-')->sortable(),
                TextColumn::make('items_count')->counts('items')->label('Items')->sortable(),
                TextColumn::make('process_status')
                    ->label('Process Status')
                    ->badge()
                    ->color(fn (StockOpnameSession $record): string => $record->approval_status === StockOpnameApprovalStatus::Posted
                        ? 'success'
                        : ($record->status === StockOpnameStatus::Draft ? 'gray' : 'info')),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (StockOpnameSession $record): string => $record->trashed()
                        ? 'Deleted'
                        : ($record->status === StockOpnameStatus::Draft ? 'Draft' : 'Saved'))
                    ->badge()
                    ->color(fn (StockOpnameSession $record): string => $record->trashed()
                        ? 'danger'
                        : ($record->status === StockOpnameStatus::Draft ? 'gray' : 'success'))
                    ->sortable(),
                TextColumn::make('notes')->limit(40)->placeholder('-'),
                TextColumn::make('created_at')->label('Created At')->dateTime()->sortable(),
                TextColumn::make('createdBy.name')->label('Created By')->placeholder('-')->sortable(),
                TextColumn::make('updated_at')->label('Updated At')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('updatedBy.name')->label('Updated By')->placeholder('-')->sortable(),
                TextColumn::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deletedBy.name')->label('Deleted By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name', fn (Builder $query): Builder => $query->saved()->where('is_active', true))
                    ->label('Location'),
                SelectFilter::make('lifecycle_status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'saved' => 'Saved',
                        'deleted' => 'Deleted',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'draft' => $query->where('status', StockOpnameStatus::Draft),
                            'saved' => $query->where('status', '!=', StockOpnameStatus::Draft),
                            'deleted' => $query->onlyTrashed(),
                            default => $query,
                        };
                    }),
                SelectFilter::make('approval_status')
                    ->label('Approval Status')
                    ->options([
                        'draft' => 'Draft',
                        'frozen' => 'Frozen',
                        'counting' => 'Counting',
                        'cancelled' => 'Cancelled',
                        'pending' => 'Pending',
                        'level_1' => 'L1 Approved',
                        'level_2' => 'L2 Approved',
                        'approved' => 'Approved',
                        'posted' => 'Posted',
                        'rejected' => 'Rejected',
                        'revised' => 'Revised',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'draft', 'frozen', 'counting', 'cancelled' => $query->where('status', $data['value']),
                            'level_1' => $query->where('approval_status', 'pending')->where('approval_level', 2),
                            'level_2' => $query->where('approval_status', 'pending')->where('approval_level', 3),
                            default => $query->when(
                                filled($data['value'] ?? null),
                                fn (Builder $query): Builder => $query->where('approval_status', $data['value']),
                            ),
                        };
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()->label('View'),
                EditAction::make()->visible(fn (StockOpnameSession $record): bool => StockOpnameResource::canEdit($record)),
                DeleteAction::make()->visible(fn (StockOpnameSession $record): bool => StockOpnameResource::canDelete($record)),
                RestoreAction::make()->visible(fn (StockOpnameSession $record): bool => StockOpnameResource::canRestore($record)),
            ])
            ->recordActionsColumnLabel('Action')
            ->toolbarActions([
                TableExportActions::group(StockOpnameResource::class),
                BulkActionGroup::make([
                    ...TableExportActions::selected(StockOpnameResource::class),
                ])
                    ->label('Bulk Actions')
                    ->icon('heroicon-o-squares-plus')
                    ->color('gray')
                    ->button(),
            ])
            ->defaultSort('updated_at', 'desc');

        return ApplicationTable::configureColumnManager($table);
    }

    private static function approvalLabel(StockOpnameSession $record): string
    {
        if ($record->approval_status === StockOpnameApprovalStatus::Posted) {
            return 'Posted';
        }

        if ($record->approval_status?->value !== 'pending') {
            return $record->approval_status?->getLabel() ?? '-';
        }

        return $record->approval_level > 1
            ? 'L'.($record->approval_level - 1).' Approved'
            : 'Pending';
    }
}
