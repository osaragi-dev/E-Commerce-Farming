<?php

namespace App\Filament\Resources\Units\Tables;

use App\Enums\RecordStatus;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Tables\ApplicationTable;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
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

class UnitsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('record_status')
                    ->options([
                        RecordStatus::Draft->value => 'Draft',
                        RecordStatus::Saved->value => 'Saved',
                        RecordStatus::Deleted->value => 'Deleted',
                    ]),
                SelectFilter::make('is_active')
                    ->label('Active')
                    ->options([1 => 'Active', 0 => 'Inactive']),
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

        return ApplicationTable::configure($table, UnitResource::class);
    }
}
