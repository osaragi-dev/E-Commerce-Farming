<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Images';

    /**
     * Filament 5 makes relation managers read-only by default when attached
     * to a ViewRecord page (Panel::hasReadOnlyRelationManagersOnResourceViewPagesByDefault()
     * defaults to true). Product photos are managed from the View page
     * (Bab 12), so this needs to explicitly opt back into being editable.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('path')
                    ->label('Photo')
                    ->image()
                    ->disk('public')
                    ->directory('products')
                    ->required(),
                Select::make('product_variant_id')
                    ->label('Variant (optional)')
                    ->relationship('productVariant', 'name', fn ($query) => $query->where('product_id', $this->getOwnerRecord()->id))
                    ->searchable()
                    ->preload()
                    ->helperText('Kosongi kalau foto ini foto umum produk, bukan khusus 1 Variant.'),
                Toggle::make('is_primary')
                    ->label('Primary Photo')
                    ->default(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->columns([
                ImageColumn::make('path')
                    ->label('Photo')
                    ->disk('public')
                    ->square(),
                TextColumn::make('productVariant.name')
                    ->label('Variant')
                    ->placeholder('— General —'),
                ToggleColumn::make('is_primary')
                    ->label('Primary'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalSubmitActionLabel('Save changes'),
                DeleteAction::make(),
            ]);
    }
}
