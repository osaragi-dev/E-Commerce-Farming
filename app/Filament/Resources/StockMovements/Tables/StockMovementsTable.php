<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockMovements\Tables\Actions\CloneStockMovementAction;
use App\Filament\Resources\StockMovements\Tables\Actions\ReceiveStockMovementAction;
use App\Filament\Resources\StockMovements\Tables\Actions\RejectStockMovementAction;
use App\Filament\Resources\StockMovements\Tables\Actions\ShipStockMovementAction;
use App\Filament\Tables\ApplicationTable;
use App\Models\StockMovement;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn(Builder $query): Builder => $query->with([
                'fromWarehouse',
                'toWarehouse',
                'productVariant.product',
                'requestedBy',
                'createdBy',
                'updatedBy',
                'deletedBy',
            ]))
            ->columns([
                TextColumn::make('row_number')->label('No')->rowIndex(),
                TextColumn::make('approval_status')
                    ->label('Approval')
                    ->state(fn(StockMovement $record): string => self::approvalLabel($record))
                    ->badge()
                    ->color(fn(StockMovement $record): string => $record->status === StockMovementStatus::Draft
                        ? 'gray'
                        : ($record->approval_status?->getColor() ?? 'gray'))
                    ->sortable(),
                TextColumn::make('movement_number')->label('Movement Number')->placeholder('-')->searchable()->sortable(),
                TextColumn::make('movement_date')->label('Movement Date')->date()->sortable(),
                TextColumn::make('fromWarehouse.name')->label('From')->searchable()->sortable(),
                TextColumn::make('toWarehouse.name')->label('To')->searchable()->sortable(),
                TextColumn::make('requestedBy.name')->label('Requested By')->placeholder('System')->sortable(),
                TextColumn::make('shipped_at')->label('Shipped At')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('received_at')->label('Received At')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn(StockMovement $record): string => $record->trashed()
                        ? 'Deleted'
                        : ($record->status === StockMovementStatus::Draft ? 'Draft' : 'Saved'))
                    ->badge()
                    ->color(fn(StockMovement $record): string => $record->trashed()
                        ? 'danger'
                        : (in_array($record->status, [StockMovementStatus::Draft, StockMovementStatus::Cancelled], true)
                            ? 'gray'
                            : 'success'))
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('Notes')
                    ->state(fn(StockMovement $record): ?string => $record->notes ?? $record->note)
                    ->limit(40)
                    ->placeholder('-'),
                TextColumn::make('created_at')->label('Created At')->dateTime()->sortable(),
                TextColumn::make('createdBy.name')->label('Created By')->placeholder('System')->sortable(),
                TextColumn::make('updated_at')->label('Updated At')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('updatedBy.name')->label('Updated By')->placeholder('System')->sortable(),
                TextColumn::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deletedBy.name')->label('Deleted By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('from_warehouse_id')
                    ->relationship('fromWarehouse', 'name', fn(Builder $query): Builder => $query->saved()->where('is_active', true))
                    ->label('From Warehouse'),
                SelectFilter::make('to_warehouse_id')
                    ->relationship('toWarehouse', 'name', fn(Builder $query): Builder => $query->saved()->where('is_active', true))
                    ->label('To Warehouse'),
                SelectFilter::make('lifecycle_status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'saved' => 'Saved',
                        'deleted' => 'Deleted',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'draft' => $query->where('status', StockMovementStatus::Draft),
                            'saved' => $query->where('status', '!=', StockMovementStatus::Draft),
                            'deleted' => $query->onlyTrashed(),
                            default => $query,
                        };
                    }),
                SelectFilter::make('approval_status')
                    ->label('Approval Status')
                    ->options([
                        'draft' => 'Draft',
                        'cancelled' => 'Cancelled',
                        'pending' => 'Pending',
                        'level_1' => 'L1 Approved',
                        'level_2' => 'L2 Approved',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'revised' => 'Revised',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'draft', 'cancelled' => $query->where('status', $data['value']),
                            'level_1' => $query->where('approval_status', 'pending')->where('approval_level', 2),
                            'level_2' => $query->where('approval_status', 'pending')->where('approval_level', 3),
                            default => $query->when(
                                filled($data['value'] ?? null),
                                fn(Builder $query): Builder => $query->where('approval_status', $data['value']),
                            ),
                        };
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn(StockMovement $record): string => StockMovementResource::getUrl('view', [
                        'record' => $record,
                    ]))
                    ->authorize(fn(StockMovement $record): bool => auth()->user()?->can('view', $record) ?? false),
                ShipStockMovementAction::make(),
                ReceiveStockMovementAction::make(),
                RejectStockMovementAction::make(),
                CloneStockMovementAction::make(),
                EditAction::make()->visible(fn(StockMovement $record): bool => StockMovementResource::canEdit($record)),
                DeleteAction::make()->visible(fn(StockMovement $record): bool => StockMovementResource::canDelete($record)),
                RestoreAction::make()->visible(fn(StockMovement $record): bool => StockMovementResource::canRestore($record)),
            ])
            ->recordActionsColumnLabel('Action')
            ->toolbarActions([
                TableExportActions::group(StockMovementResource::class),
                BulkActionGroup::make([
                    ...TableExportActions::selected(StockMovementResource::class),
                ])
                    ->label('Bulk Actions')
                    ->icon('heroicon-o-squares-plus')
                    ->color('gray')
                    ->button(),
            ])
            ->defaultSort('updated_at', 'desc');

        return ApplicationTable::configureColumnManager($table);
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
            ? 'L' . ($record->approval_level - 1) . ' Approved'
            : 'Pending';
    }
}
