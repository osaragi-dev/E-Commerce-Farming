<?php

namespace App\Filament\Resources\Sectors\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Auto-slug sekarang murni Alpine.js/DOM (client-side) — TIDAK lewat
 * live()/Livewire sama sekali. Ini disengaja: live() (baik onBlur
 * maupun debounce) terbukti bikin request menumpuk ke server tiap
 * ketikan (lihat riwayat debugging: 13 request cuma buat 1 nama).
 * Cara ini cari input Slug lewat atribut wire:model-nya sendiri
 * (bukan nebak path Livewire), set nilainya, lalu "pura-pura" user
 * ngetik di situ (dispatchEvent) — Livewire sendiri yang nangkep
 * perubahan itu lewat binding wire:model yang SUDAH ADA di field
 * Slug, tanpa request tambahan apapun sampai Save beneran diklik.
 */
class SectorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
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
                    ->helperText('Unique untuk sector yang tersimpan. Otomatis mengikuti Name, tapi tetap bisa diedit manual.'),
                FileUpload::make('image')
                    ->label('Image')
                    ->image()
                    ->disk('public')
                    ->directory('sectors')
                    ->required(false),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
