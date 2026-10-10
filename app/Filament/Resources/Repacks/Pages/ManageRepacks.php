<?php

namespace App\Filament\Resources\Repacks\Pages;

use App\Filament\Actions\LifecycleCreateAction;
use App\Filament\Resources\Repacks\RepackResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRepacks extends ManageRecords
{
    protected static string $resource = RepackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LifecycleCreateAction::make()->modalHeading('Create Convert')->databaseTransaction()
                ->extraModalFooterActions(fn (CreateAction $action): array => [
                    $action->makeModalSubmitAction('saveDraft', ['draft' => true])->label('Save Draft')->color('gray'),
                ]),
        ];
    }
}
