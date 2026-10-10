<?php

namespace App\Filament\Resources\Vendors\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Basic Info')->columns(2)->schema([
                TextInput::make('vendor_code')->label('Vendor Code')->required()->maxLength(100)->helperText('Must be unique among saved vendors. Drafts may reuse a code.'),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('contact_person')->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(50),
                TextInput::make('email')->email()->maxLength(255),
                Textarea::make('address')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Tax & Bank')->columns(2)->schema([
                TextInput::make('tax_id')->label('Tax ID')->maxLength(100),
                TextInput::make('bank_name')->maxLength(255),
                TextInput::make('bank_account')->maxLength(100),
                TextInput::make('bank_account_name')->maxLength(255),
            ]),
            Section::make('Terms')->columns(2)->schema([
                TextInput::make('payment_terms')->maxLength(255),
                TextInput::make('lead_time_days')->label('Lead Time (Days)')->numeric()->integer()->minValue(0)->default(0)->required(),
                Toggle::make('is_active')->label('Active')->default(true),
            ]),
            Section::make('Notes')->schema([
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }
}
