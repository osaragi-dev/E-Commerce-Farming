<?php

namespace App\Filament\Resources\ApprovalSettings\Tables;

use App\Enums\ApprovalModule;
use App\Filament\Resources\ApprovalSettings\ApprovalSettingResource;
use App\Filament\Tables\ApplicationTable;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApprovalSettingsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('module_code')
                    ->label('Module')
                    ->formatStateUsing(fn (string $state): string => ApprovalModule::tryFrom($state)?->getLabel() ?? str($state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('levels_count')
                    ->label('Approval Levels')
                    ->counts('levels')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Updated At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge),
            ]);

        return ApplicationTable::configure(
            $table,
            ApprovalSettingResource::class,
        );
    }
}
