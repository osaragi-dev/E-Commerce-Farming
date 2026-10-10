<?php

namespace App\Filament\Resources\ApprovalSettings\Pages;

use App\Filament\Resources\ApprovalSettings\ApprovalSettingResource;
use Filament\Resources\Pages\ManageRecords;

class ManageApprovalSettings extends ManageRecords
{
    protected static string $resource = ApprovalSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
