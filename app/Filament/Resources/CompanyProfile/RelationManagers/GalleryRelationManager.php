<?php

namespace App\Filament\Resources\CompanyProfile\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GalleryRelationManager extends RelationManager
{
    protected static string $relationship = 'galleries';

    protected static ?string $title = 'Gallery';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('image')
                    ->label('Photo')
                    ->image()
                    ->disk('public')
                    ->directory('company-profile')
                    ->required(),
                Textarea::make('description')
                    ->label('Description')
                    ->rows(4),
                TextInput::make('sort_order')
                    ->label('Sort Order')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('image')
            ->columns([
                ImageColumn::make('image')
                    ->label('Photo')
                    ->disk('public')
                    ->size(64)
                    ->square()
                    ->width('72px'),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(48)
                    ->wrap()
                    ->grow()
                    ->placeholder('-'),
                TextColumn::make('sort_order')
                    ->label('Sort Order')
                    ->numeric()
                    ->alignCenter()
                    ->width('100px')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter()
                    ->width('80px'),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('160px')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated At')
                    ->dateTime()
                    ->placeholder('-')
                    ->width('160px')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Active')
                    ->options([1 => 'Active', 0 => 'Inactive']),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('sort_order')
                    ->orderBy('created_at')
                    ->orderBy('id');
            })
            ->headerActions([
                CreateAction::make()
                    ->schema(fn (Schema $schema): Schema => $this->form($schema->columns(1))),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema(fn (Schema $schema): Schema => $this->form($schema->columns(1)))
                    ->modalSubmitActionLabel('Save'),
                DeleteAction::make(),
            ]);
    }
}
