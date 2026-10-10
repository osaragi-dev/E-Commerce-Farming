<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Actions\AssignUserRoleAction;
use App\Filament\Actions\ImpersonateUserAction;
use App\Filament\Actions\ResetUserPasswordAction;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Tables\ApplicationTable;
use App\Models\Role;
use App\Models\User;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->placeholder('-')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $role = new Role;
                        $roleTable = $role->getTable();
                        $roleKey = $role->getKeyName();
                        $pivotTable = config('permission.table_names.model_has_roles', 'model_has_roles');
                        $pivotRoleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
                        $pivotModelKey = config('permission.column_names.model_morph_key', 'model_id');
                        $userTable = $query->getModel()->getTable();
                        $userKey = $query->getModel()->getKeyName();

                        $roleName = Role::query()
                            ->select("{$roleTable}.name")
                            ->join($pivotTable, "{$pivotTable}.{$pivotRoleKey}", '=', "{$roleTable}.{$roleKey}")
                            ->where("{$pivotTable}.model_type", User::class)
                            ->whereColumn("{$pivotTable}.{$pivotModelKey}", "{$userTable}.{$userKey}")
                            ->orderBy("{$roleTable}.name")
                            ->limit(1);

                        return $query->orderBy($roleName, $direction);
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->placeholder('-')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortByAuditActor(
                        $query,
                        'created_by',
                        'created_by_user',
                        $direction,
                    ))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Updated At')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('updatedBy.name')
                    ->label('Updated By')
                    ->placeholder('-')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortByAuditActor(
                        $query,
                        'updated_by',
                        'updated_by_user',
                        $direction,
                    ))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->label('Deleted At')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deletedBy.name')
                    ->label('Deleted By')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship(
                        'roles',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('name', '!=', 'customer'),
                    )
                    ->label('Role'),
                SelectFilter::make('is_active')
                    ->options([1 => 'Active', 0 => 'Not Active']),
                SelectFilter::make('created_by')
                    ->relationship('createdBy', 'name')
                    ->label('Created By'),
                SelectFilter::make('updated_by')
                    ->relationship('updatedBy', 'name')
                    ->label('Updated By'),
                SelectFilter::make('deleted_by')
                    ->relationship('deletedBy', 'name')
                    ->label('Deleted By'),
                DateRangeFilter::make('created_at')->label('Created Date'),
                DateRangeFilter::make('deleted_at')->label('Deleted Date'),
                TrashedFilter::make(),
            ])
            ->filtersFormWidth(Width::Small)
            ->recordActions([
                EditAction::make()->hidden(fn (User $record): bool => $record->trashed()),
                ResetUserPasswordAction::make(),
                AssignUserRoleAction::make(),
                ImpersonateUserAction::make(),
            ])
            ->toolbarActions([
                TableExportActions::group(UserResource::class),
                BulkActionGroup::make([
                    ...TableExportActions::selected(UserResource::class),
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

        return ApplicationTable::configureColumnManager($table);
    }

    private static function sortByAuditActor(
        Builder $query,
        string $foreignKey,
        string $alias,
        string $direction,
    ): Builder {
        $userTable = $query->getModel()->getTable();
        $qualifiedForeignKey = $query->getModel()->qualifyColumn($foreignKey);

        return $query
            ->leftJoin("{$userTable} as {$alias}", function (JoinClause $join) use ($alias, $qualifiedForeignKey): void {
                $join
                    ->on("{$alias}.id", '=', $qualifiedForeignKey)
                    ->whereNull("{$alias}.deleted_at");
            })
            ->orderBy("{$alias}.name", $direction)
            ->select("{$userTable}.*");
    }
}
