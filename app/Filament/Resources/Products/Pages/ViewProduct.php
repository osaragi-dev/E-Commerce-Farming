<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Actions\LifecycleEditAction;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LifecycleEditAction::configure(EditAction::make()),
        ];
    }
}
