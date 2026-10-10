<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerType;
use App\Models\Customer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_type')
                    ->label('Customer Type')
                    ->options(CustomerType::class)
                    ->default(CustomerType::Regular->value)
                    ->required(),
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
                // QA revision (28 Aug): Email dibuat opsional, dan tidak
                // lagi pakai ->unique() bawaan Filament — validasi
                // duplikat email sudah ditangani LifecycleIdentifierValidator
                // (lifecycleUniqueAttributes() di Customer model), yang
                // sudah benar mengabaikan record yang sudah dihapus
                // (whereNull('deleted_at')). ->unique() polos di sini
                // menabrak record ter-soft-delete dan bikin email yang
                // sudah dihapus tidak bisa dipakai ulang.
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->helperText('This menu manages customer profiles from sign-up and manual entry. Manual entries do not create login accounts.')
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('Phone')
                    ->tel()
                    ->maxLength(20),
                Select::make('address')
                    ->label('Address')
                    ->options([
                        ...array_combine(Customer::ADDRESS_OPTIONS, Customer::ADDRESS_OPTIONS),
                        'Others' => 'Others',
                    ])
                    ->searchable()
                    ->live()
                    ->afterStateHydrated(function (Set $set, ?string $state): void {
                        if (filled($state) && ! in_array($state, Customer::ADDRESS_OPTIONS, true)) {
                            $set('address', 'Others');
                            $set('other_address', $state);
                        }
                    })
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state !== 'Others') {
                            $set('other_address', null);
                        }
                    })
                    ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => $state === 'Others'
                        ? $get('other_address')
                        : $state)
                    ->columnSpanFull(),
                Textarea::make('other_address')
                    ->label('Other Address')
                    ->placeholder('Enter the customer address')
                    ->visible(fn (Get $get): bool => $get('address') === 'Others')
                    ->required(fn (Get $get): bool => $get('address') === 'Others')
                    ->rows(3)
                    ->maxLength(500)
                    ->dehydrated(false)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ])
            ->columns(2);
    }
}
