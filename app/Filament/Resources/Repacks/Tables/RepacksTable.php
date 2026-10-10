<?php

namespace App\Filament\Resources\Repacks\Tables;

use App\Filament\Actions\LifecycleEditAction;
use App\Filament\Resources\Repacks\RepackResource;
use App\Filament\Resources\Repacks\Schemas\RepackInfolist;
use App\Filament\Tables\ApplicationTable;
use App\Models\Repack;
use App\Models\SystemFreezeState;
use App\Support\Quantity;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RepacksTable
{
    public static function configure(Table $table): Table
    {
        $columns = [
            TextColumn::make('row_number')->label('No')->rowIndex(),
            TextColumn::make('repack_number')->label('Convert Number')->placeholder('-')->searchable(),
            TextColumn::make('warehouse.name')->label('Location')->searchable(),
            TextColumn::make('fromProductVariant.inventory_label')->label('From Product')
                ->searchable(query: fn (Builder $query, string $search): Builder => self::searchProduct($query, 'fromProductVariant', $search))
                ->sortable(['name']),
            TextColumn::make('from_quantity')->label('From Qty')->formatStateUsing(fn ($state): string => Quantity::format($state)),
            TextColumn::make('from_unit')->label('From Unit'),
            TextColumn::make('toProductVariant.inventory_label')->label('To Product')
                ->searchable(query: fn (Builder $query, string $search): Builder => self::searchProduct($query, 'toProductVariant', $search))
                ->sortable(['name']),
            TextColumn::make('to_quantity')->label('To Qty')->formatStateUsing(fn ($state): string => Quantity::format($state)),
            TextColumn::make('to_unit')->label('To Unit'),
            TextColumn::make('record_status')->label('Status')->state(fn (Repack $record): string => $record->display_status)
                ->badge()->color(fn (Repack $record): string => $record->cancelled_at !== null ? 'danger' : $record->record_status->getColor()),
            TextColumn::make('notes')->label('Notes')->placeholder('-')->limit(60)->searchable(),
            TextColumn::make('created_at')->label('Created At')->dateTime(),
            TextColumn::make('createdBy.name')->label('Created By'),
            TextColumn::make('updated_at')->label('Updated At')->dateTime()->placeholder('-'),
            TextColumn::make('updatedBy.name')->label('Updated By')->placeholder('-'),
            TextColumn::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-'),
            TextColumn::make('deletedBy.name')->label('Deleted By')->placeholder('-'),
        ];
        $table->columns($columns)->filters([
            SelectFilter::make('warehouse_id')->label('Location')->relationship('warehouse', 'name'),
            SelectFilter::make('record_status')->label('Status')
                ->options(['draft' => 'Draft', 'saved' => 'Saved', 'cancelled' => 'Cancelled', 'deleted' => 'Deleted'])
                ->query(function (Builder $query, array $data): Builder {
                    $status = $data['value'] ?? null;
                    if (! $status) {
                        return $query;
                    }

                    return $status === 'cancelled' ? $query->whereNotNull('cancelled_at')
                        : $query->where('record_status', $status)->whereNull('cancelled_at');
                }),
            DateRangeFilter::make('created_at')->label('Created Date')->timezone('Asia/Jakarta'),
            TrashedFilter::make(),
        ])->recordActions([
            ViewAction::make()->schema(fn (Schema $schema): Schema => RepackInfolist::configure($schema)),
            EditAction::make()->databaseTransaction()->visible(fn (Repack $record): bool => RepackResource::canEdit($record)),
            Action::make('cancel')->label('Cancel Convert')->icon('heroicon-o-x-circle')->color('danger')->requiresConfirmation()
                ->modalDescription('Restore the source stock, remove the target stock, and reverse the source Out quantity?')
                ->visible(fn (Repack $record): bool => $record->isCancellable() && ! SystemFreezeState::current()->is_frozen)
                ->authorize(fn (Repack $record): bool => Gate::allows('cancel', $record))
                ->action(function (Repack $record, Action $action): void {
                    Gate::authorize('cancel', $record);
                    try {
                        $record->cancel(auth()->id());
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Cannot cancel Convert')->body($exception->getMessage())->danger()->send();
                        $action->halt();
                    }
                    Notification::make()->title('Convert cancelled and stock restored.')->success()->send();
                }),
            ApplicationTable::cloneAction(RepackResource::class)
                ->modalHeading('Clone Convert')
                ->modalSubmitAction(fn (Action $action): Action => $action->label('Create')->color('primary'))
                ->databaseTransaction()
                ->authorize(fn (Repack $record): bool => RepackResource::canCreate() && Gate::allows('replicate', $record))
                ->excludeAttributes([
                    'id', 'repack_number', 'record_status', 'previous_record_status', 'created_by', 'updated_by', 'deleted_by',
                    'created_at', 'updated_at', 'deleted_at', 'applied_at', 'stock_out_applied_at', 'cancelled_at', 'cancelled_by',
                    'from_unit', 'to_unit',
                ]),
            DeleteAction::make()->visible(fn (Repack $record): bool => RepackResource::canDelete($record)),
            RestoreAction::make()->visible(fn (Repack $record): bool => RepackResource::canRestore($record)),
        ])->toolbarActions([
            BulkActionGroup::make([
                DeleteBulkAction::make()->authorizeIndividualRecords(fn (Repack $record): bool => RepackResource::canDelete($record)),
                RestoreBulkAction::make()->authorizeIndividualRecords(fn (Repack $record): bool => RepackResource::canRestore($record)),
            ]),
        ])->splitSearchTerms(false);

        $table = ApplicationTable::configure($table, RepackResource::class);
        $actions = $table->getRecordActions();
        foreach ($actions as $action) {
            if ($action instanceof EditAction) {
                LifecycleEditAction::configure($action)
                    ->modalSubmitAction(fn (Action $action): Action => $action->label('Submit')->icon('heroicon-o-check')
                        ->color('primary')->extraAttributes(['formnovalidate' => true]))
                    ->using(function (Repack $record, array $data): Repack {
                        Gate::authorize('update', $record);
                        $record->fill($data)->save();

                        return $record;
                    });
            }
        }
        foreach ($columns as $column) {
            $column->toggleable();
            if (! $column->isSortable() && $column->getName() !== 'row_number') {
                $column->sortable();
            }
        }

        return $table->columns($columns)->recordActions($actions)->recordActionsColumnLabel('Action');
    }

    private static function searchProduct(Builder $query, string $relationship, string $search): Builder
    {
        return $query->whereHas($relationship, fn (Builder $variant): Builder => $variant
            ->where('name', 'like', '%'.$search.'%')
            ->orWhereHas('product', fn (Builder $product): Builder => $product->where('name', 'like', '%'.$search.'%')));
    }
}
