<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Models\Warehouse;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->maxLength(50)
                ->helperText('Unique among saved records. Drafts may reuse a code.'),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Toggle::make('is_active')->default(true),
            Toggle::make('is_pos')
                ->label('Is POS')
                ->default(false)
                ->afterStateUpdated(function (Set $set, ?bool $state, ?Warehouse $record): void {
                    if (! $state) {
                        return;
                    }

                    $activePos = Warehouse::query()
                        ->saved()
                        ->where('is_active', true)
                        ->where('is_pos', true)
                        ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->first();

                    if (! $activePos) {
                        return;
                    }

                    $set('is_pos', false);

                    Notification::make()
                        ->danger()
                        ->title('POS Already Active')
                        ->body("POS is already active on location '{$activePos->name}'. Only one location can have POS active.")
                        ->persistent()
                        ->send();
                }),
            Textarea::make('description')->rows(4)->columnSpanFull(),
        ]);
    }
}
