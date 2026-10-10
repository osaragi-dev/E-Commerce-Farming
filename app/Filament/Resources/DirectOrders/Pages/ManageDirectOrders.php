<?php

namespace App\Filament\Resources\DirectOrders\Pages;

use App\Filament\Resources\DirectOrders\DirectOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageDirectOrders extends ManageRecords
{
    protected static string $resource = DirectOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('create')
            ->label('Create Direct Order')
            ->icon(Heroicon::Plus)
            ->url(DirectOrderResource::getUrl('create'))];
    }
}
