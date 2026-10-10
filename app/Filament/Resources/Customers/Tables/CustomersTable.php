<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Tables\ApplicationTable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('customer_type')
                    ->label('Customer Type')
                    ->badge(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('account_status')
                    ->label('Account')
                    ->state(fn ($record): string => $record->accountStatusLabel())
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Registered' ? 'success' : 'gray'),
                IconColumn::make('email_verified_at')
                    ->label('Email Verified')
                    ->boolean()
                    ->state(fn ($record): bool => $record->hasVerifiedEmail()),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                // QA revision (28 Aug): filter "Tanggal Daftar" (created_at)
                // dihapus, diganti TrashedFilter — sebelumnya record yang
                // sudah di-soft-delete tidak bisa ditemukan sama sekali di
                // tabel ini (Filament sembunyikan trashed record secara
                // default tanpa TrashedFilter), jadi tombol Restore tidak
                // pernah bisa diklik. Ini akar masalah bug CRITICAL
                // "belum bisa restore".
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->label('Edit'),
                DeleteAction::make()->label('Delete'),
                RestoreAction::make()->label('Restore'),
                ApplicationTable::cloneAction(CustomerResource::class, allowDraft: false)
                    ->authorize(fn (): bool => CustomerResource::canCreate()
                        && (auth()->user()?->can('Replicate:Customer') ?? false)),
            ]);

        // TCS revision (25 Aug 2026): dibungkus ApplicationTable, sama
        // kayak Sectors/Categories/Products — otomatis dapet kolom
        // Record Status, Created/Updated By, tombol Clone, dan Export,
        // tanpa perlu nulis ulang semua itu manual.
        return ApplicationTable::configure($table, CustomerResource::class);
    }
}
