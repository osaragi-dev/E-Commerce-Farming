<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManagePurchaseRequests extends ManageRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('create')
            ->label('Add Purchase Request')
            ->icon(Heroicon::Plus)
            ->url(PurchaseRequestResource::getUrl('create'))];
    }
}
