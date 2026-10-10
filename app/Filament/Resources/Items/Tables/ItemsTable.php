<?php

namespace App\Filament\Resources\Items\Tables;

use App\Enums\ItemType;
use App\Enums\RecordStatus;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Support\Rupiah;
use App\Filament\Tables\ApplicationTable;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('code')->label('Code')->searchable()->sortable(),
                TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('name')->label('Name')->searchable()->sortable(),
                TextColumn::make('item_type')->label('Item Type')->badge(),
                TextColumn::make('unit.code')->label('Base Unit')->sortable(),
                TextColumn::make('min_stock')->label('Min Stock')->numeric(decimalPlaces: 3)->sortable(),
                TextColumn::make('avg_purchase_price')
                    ->label('Avg Purchase Price')
                    ->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state))
                    ->sortable(),
                TextColumn::make('purchasable')->label('Purchasable')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')->sortable(),
                TextColumn::make('shelf_life_days')->label('Shelf Life (Days)')->placeholder('-')->sortable(),
                TextColumn::make('is_active')->label('Active')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')->sortable(),
                TextColumn::make('record_status')->label('Status')->badge(),
                TextColumn::make('notes')->limit(40)->toggleable(),
                TextColumn::make('createdBy.name')->label('Created By'),
                TextColumn::make('created_at')->label('Created At')->dateTime()->sortable(),
                TextColumn::make('updatedBy.name')->label('Updated By'),
                TextColumn::make('updated_at')->label('Updated At')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('item_type')->options(ItemType::class),
                SelectFilter::make('record_status')->options([
                    RecordStatus::Draft->value => 'Draft',
                    RecordStatus::Saved->value => 'Saved',
                    RecordStatus::Deleted->value => 'Deleted',
                ]),
                SelectFilter::make('is_active')->label('Active')->options([1 => 'Active', 0 => 'Inactive']),
                SelectFilter::make('purchasable')->options([1 => 'Yes', 0 => 'No']),
                DateRangeFilter::make('created_at')->label('Created Date'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);

        $table = ApplicationTable::configure(
            $table,
            ItemResource::class,
            statusLabel: 'Status',
            rowNumberLabel: 'No',
        )->recordActionsColumnLabel('Action');

        foreach (['createdBy.name', 'created_at', 'updatedBy.name', 'updated_at'] as $columnName) {
            $table->getColumn($columnName)?->toggleable(isToggledHiddenByDefault: false);
        }

        return $table;
    }
}
