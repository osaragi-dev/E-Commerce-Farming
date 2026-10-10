<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Actions\LifecycleCreateAction;
use App\Filament\Resources\Warehouses\WarehouseResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWarehouses extends ManageRecords
{
    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [LifecycleCreateAction::make()];
    }
}
