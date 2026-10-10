<?php

namespace App\Filament\Resources\Warehouses\Tables;

use App\Enums\RecordStatus;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Filament\Tables\ApplicationTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class WarehousesTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('is_pos')->label('Is POS')->boolean(),
            ])
            ->filters([
                SelectFilter::make('record_status')->options([
                    RecordStatus::Draft->value => 'Draft',
                    RecordStatus::Saved->value => 'Saved',
                    RecordStatus::Deleted->value => 'Deleted',
                ]),
                SelectFilter::make('is_active')
                    ->label('Active')
                    ->options([1 => 'Active', 0 => 'Inactive']),
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

        return ApplicationTable::configure($table, WarehouseResource::class, true, [
            'created_at',
            'createdBy.name',
            'updated_at',
            'updatedBy.name',
            'deleted_at',
            'deletedBy.name',
        ]);
    }
}
