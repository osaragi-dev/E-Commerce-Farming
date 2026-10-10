<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\RecordStatus;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Tables\ApplicationTable;
use App\Models\Category;
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

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn () => Category::query()->saved()->pluck('name', 'id')),
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

        return ApplicationTable::configure($table, ProductResource::class);
    }
}
