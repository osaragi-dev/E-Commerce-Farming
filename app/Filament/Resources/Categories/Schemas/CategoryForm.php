<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Sector;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('sector_id')
                    ->label('Sector')
                    ->options(fn () => Sector::query()->saved()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(100)
                    ->extraInputAttributes([
                        'x-on:input' => <<<'JS'
                            let slug = $el.value
                                .toString()
                                .toLowerCase()
                                .trim()
                                .replace(/[^a-z0-9]+/g, '-')
                                .replace(/^-+|-+$/g, '');
                            let container = $el.closest('form, [wire\\:id]');
                            let slugInput = container?.querySelector('input[wire\\:model*=slug]');
                            if (slugInput) {
                                slugInput.value = slug;
                                slugInput.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                        JS,
                    ]),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(120)
                    ->helperText('Unique untuk category yang tersimpan. Otomatis mengikuti Name, tapi tetap bisa diedit manual.'),
                Toggle::make('is_active')->default(true),
            ]);
    }
}
