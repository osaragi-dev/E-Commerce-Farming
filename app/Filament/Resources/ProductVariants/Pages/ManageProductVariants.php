<?php

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Actions\LifecycleCreateAction;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use Filament\Resources\Pages\ManageRecords;

class ManageProductVariants extends ManageRecords
{
    protected static string $resource = ProductVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LifecycleCreateAction::make(),
        ];
    }
}
