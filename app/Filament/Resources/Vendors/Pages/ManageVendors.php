<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Actions\LifecycleCreateAction;
use App\Filament\Resources\Vendors\VendorResource;
use Filament\Resources\Pages\ManageRecords;

class ManageVendors extends ManageRecords
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [LifecycleCreateAction::make(draftLabel: 'Save Draft')];
    }
}
