<?php

namespace App\Filament\Tables;

use App\Enums\RecordStatus;
use App\Filament\Actions\LifecycleEditAction;
use App\Filament\Actions\TableExportActions;
use App\Filament\Contracts\HasTableExport;
use App\Filament\Validation\LifecycleIdentifierValidator;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApplicationTable
{
    /** @var list<string> */
    private const SUPPORTING_COLUMN_NAMES = [
        'row_number',
        'id',
        'createdBy.name',
        'updatedBy.name',
        'deletedBy.name',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** @var list<string> */
    private const CORE_STATUS_COLUMN_NAMES = [
        'record_status',
    ];

    /**
     * @param  class-string<\Filament\Resources\Resource&HasTableExport>  $resource
     */
    public static function configure(
        Table $table,
        string $resource,
        bool $hasLifecycle = true,
        ?array $auditColumnOrder = null,
        ?string $statusLabel = null,
        string $rowNumberLabel = '#',
    ): Table {
        $auditColumnOrder ??= [
            'createdBy.name',
            'created_at',
            'updatedBy.name',
            'updated_at',
            ...($hasLifecycle ? ['deletedBy.name', 'deleted_at'] : []),
        ];

        $columns = $table->getColumns();
        $recordActions = [];
        $hasCloneAction = false;

        unset(
            $columns['row_number'],
            $columns['id'],
            $columns['record_status'],
            $columns['createdBy.name'],
            $columns['updatedBy.name'],
            $columns['deletedBy.name'],
            $columns['created_at'],
            $columns['updated_at'],
            $columns['deleted_at'],
        );

        foreach ($columns as $column) {
            $column->toggleable(
                isToggledHiddenByDefault: $column->isToggledHiddenByDefault(),
            );

            if (! $column->isSortable()) {
                $column->sortable();
            }
        }

        foreach ($table->getRecordActions() as $recordAction) {
            if ($recordAction instanceof ForceDeleteAction) {
                continue;
            }

            if ($recordAction instanceof EditAction) {
                if ($hasLifecycle) {
                    LifecycleEditAction::configure($recordAction);
                }

                $recordAction->hidden(fn (Model $record): bool => self::isTrashed($record));
            }

            if ($recordAction instanceof DeleteAction) {
                $recordAction->hidden(fn (Model $record): bool => self::isTrashed($record));
            }

            if ($recordAction instanceof RestoreAction) {
                $recordAction->after(function (Model $record): void {
                    if ($record->getAttribute('record_status') !== RecordStatus::Draft
                        && $record->getAttribute('record_status') !== RecordStatus::Draft->value) {
                        return;
                    }

                    Notification::make()
                        ->title('Record restored as Draft')
                        ->body('Its unique identifier is already used by a saved record.')
                        ->warning()
                        ->send();
                });
            }

            if ($recordAction instanceof ReplicateAction) {
                $hasCloneAction = true;
            }

            $recordActions[] = $recordAction;
        }

        if ($resource::canCreate() && ! $hasCloneAction) {
            $recordActions[] = self::cloneAction($resource, $hasLifecycle);
        }

        $toolbarActions = [];
        $bulkActions = [];

        foreach ($table->getToolbarActions() as $toolbarAction) {
            if ($toolbarAction instanceof BulkActionGroup) {
                foreach ($toolbarAction->getFlatActions() as $bulkAction) {
                    if ($bulkAction instanceof BulkAction
                        && ! Str::contains($bulkAction->getName(), 'forceDelete', ignoreCase: true)) {
                        $bulkActions[] = $bulkAction;
                    }
                }

                continue;
            }

            $toolbarActions[] = $toolbarAction;
        }

        $auditColumns = [
            'createdBy.name' => TextColumn::make('createdBy.name')
                ->label('Created By')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            'created_at' => TextColumn::make('created_at')
                ->label('Created At')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            'updatedBy.name' => TextColumn::make('updatedBy.name')
                ->label('Updated By')
                ->placeholder('-')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            'updated_at' => TextColumn::make('updated_at')
                ->label('Updated At')
                ->placeholder('-')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            'deletedBy.name' => TextColumn::make('deletedBy.name')
                ->label('Deleted By')
                ->placeholder('-')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            'deleted_at' => TextColumn::make('deleted_at')
                ->label('Deleted At')
                ->placeholder('-')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];

        $table = $table
            ->columns([
                TextColumn::make('row_number')->label($rowNumberLabel)->rowIndex(),
                ...array_values($columns),
                ...($hasLifecycle ? [
                    TextColumn::make('record_status')
                        ->label($statusLabel ?? 'Record Status')
                        ->badge()
                        ->sortable()
                        ->toggleable(),
                ] : []),
                ...array_map(
                    fn (string $column): TextColumn => $auditColumns[$column],
                    $auditColumnOrder,
                ),
            ])
            ->recordActions($recordActions)
            ->recordActionsPosition(RecordActionsPosition::AfterColumns)
            ->toolbarActions([
                ...$toolbarActions,
                TableExportActions::group($resource),
                BulkActionGroup::make([
                    ...TableExportActions::selected($resource),
                    ...$bulkActions,
                ])
                    ->label('Bulk Actions')
                    ->icon('heroicon-o-squares-plus')
                    ->color('gray')
                    ->button(),
            ])
            ->defaultSort(function (Builder $query): Builder {
                $grammar = $query->getQuery()->getGrammar();
                $updatedAt = $grammar->wrap($query->getModel()->getQualifiedUpdatedAtColumn());
                $createdAt = $grammar->wrap($query->getModel()->getQualifiedCreatedAtColumn());

                return $query->orderByRaw("COALESCE({$updatedAt}, {$createdAt}) DESC");
            });

        return self::configureColumnManager($table);
    }

    public static function configureColumnManager(Table $table): Table
    {
        foreach ($table->getColumns() as $column) {
            $column->toggleable(
                isToggledHiddenByDefault: $column->isToggledHiddenByDefault(),
            );
        }

        $primaryColumnName = collect($table->getColumns())
            ->reject(fn (Column $column): bool => in_array($column->getName(), [
                ...self::SUPPORTING_COLUMN_NAMES,
                ...self::CORE_STATUS_COLUMN_NAMES,
            ], true))
            ->first()?->getName();

        foreach ($table->getColumns() as $column) {
            if (! self::isCoreColumn($column, $primaryColumnName)) {
                continue;
            }

            $column->toggleable(false);
        }

        return $table->deferColumnManager(false);
    }

    /**
     * @param  class-string<\Filament\Resources\Resource&HasTableExport>  $resource
     */
    public static function cloneAction(
        string $resource,
        bool $hasLifecycle = true,
        bool $allowDraft = true,
        array $additionalExcludedAttributes = [],
    ): ReplicateAction {
        $action = ReplicateAction::make('clone')
            ->label('Clone')
            ->color('primary')
            ->icon('heroicon-o-document-duplicate')
            ->hidden(fn (?Model $record): bool => $record !== null && self::isTrashed($record))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(fn (Action $action): Action => $action
                ->label('Save')
                ->color('primary'))
            ->excludeAttributes(fn (?Model $record): array => [
                'id',
                'slug',
                'record_status',
                'previous_record_status',
                'created_by',
                'updated_by',
                'deleted_by',
                'created_at',
                'updated_at',
                'deleted_at',
                ...$additionalExcludedAttributes,
                // Kolom "hitungan" (categories_count, products_count,
                // variants_count, dst) itu virtual — hasil withCount() di
                // query List/Table, BUKAN kolom asli di database. Kalau
                // ikut ke-replicate, INSERT-nya gagal karena kolom itu
                // memang tidak ada ("column ... does not exist"). Filter
                // otomatis apa pun namanya, bukan cuma yang kita tau
                // sekarang, biar resource baru di masa depan otomatis aman.
                ...array_filter(
                    array_keys($record?->getAttributes() ?? []),
                    fn (string $attribute): bool => str_ends_with($attribute, '_count'),
                ),
            ])
            ->schema(fn (Schema $schema): Schema => $resource::form(
                $schema->columns(2)->operation('create'),
            ));

        if (! $hasLifecycle) {
            return $action;
        }

        return $action
            ->extraModalFooterActions($allowDraft
                ? fn (ReplicateAction $action): array => [
                    $action->makeModalSubmitAction('saveDraft', ['draft' => true])
                        ->label('Save as Draft')
                        ->icon('heroicon-o-document')
                        ->color('gray')
                        ->extraAttributes(['formnovalidate' => true]),
                ]
                : fn (): array => [])
            ->before(fn (
                ReplicateAction $action,
                LifecycleIdentifierValidator $validator,
                array $arguments,
            ) => $validator->validate(
                $action,
                ($arguments['draft'] ?? false) ? RecordStatus::Draft : RecordStatus::Saved,
                false,
            ))
            ->beforeReplicaSaved(fn (Model $replica, array $arguments) => $replica->forceFill([
                'record_status' => ($arguments['draft'] ?? false)
                    ? RecordStatus::Draft
                    : RecordStatus::Saved,
                'previous_record_status' => null,
                'created_by' => null,
                'updated_by' => null,
                'deleted_by' => null,
            ]));
    }

    private static function isTrashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed();
    }

    private static function isCoreColumn(
        Column $column,
        ?string $primaryColumnName,
    ): bool {
        return $column->getName() === 'row_number'
            || $column->getName() === $primaryColumnName
            || in_array(
                $column->getName(),
                self::CORE_STATUS_COLUMN_NAMES,
                true,
            );
    }
}
