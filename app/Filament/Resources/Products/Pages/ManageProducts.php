<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Actions\LifecycleCreateAction;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\ManageRecords;

class ManageProducts extends ManageRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LifecycleCreateAction::make(),
        ];
    }
}
