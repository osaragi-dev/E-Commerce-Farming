<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Actions\LifecycleCreateAction;
use App\Filament\Resources\Items\ItemResource;
use Filament\Resources\Pages\ManageRecords;

class ManageItems extends ManageRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [LifecycleCreateAction::make(draftLabel: 'Save Draft')];
    }
}
