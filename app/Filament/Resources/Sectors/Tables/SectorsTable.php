<?php

namespace App\Filament\Resources\Sectors\Tables;

use App\Enums\RecordStatus;
use App\Filament\Resources\Sectors\SectorResource;
use App\Filament\Tables\ApplicationTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class SectorsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                ImageColumn::make('image')
                    ->label('Image')
                    ->disk('public')
                    ->square(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('categories_count')
                    ->counts('categories')
                    ->label('Categories'),
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
                    ->options([1 => 'Active', 0 => 'Inactive']),
                TrashedFilter::make(),
            ])
            ->defaultSort('sort_order')
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

        return ApplicationTable::configure($table, SectorResource::class);
    }
}
