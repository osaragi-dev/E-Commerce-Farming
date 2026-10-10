<?php

namespace App\Filament\Resources\ShippingSetting\Schemas;

use App\Filament\Forms\Components\RupiahInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShippingSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Shipping')
                    ->schema([
                        RupiahInput::make('flat_shipping_cost')
                            ->label('Ongkos Kirim Flat')
                            ->helperText('Estimasi ongkir yang ditampilkan ke customer saat checkout (belum berdasarkan jarak). Bisa disesuaikan manual per pesanan lewat menu Orders.')
                            ->default(15000)
                            ->required(),
                    ]),
            ]);
    }
}
