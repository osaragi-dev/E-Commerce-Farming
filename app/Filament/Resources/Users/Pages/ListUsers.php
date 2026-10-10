<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ListUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add User')
                ->modalDescription('After saving, use Assign Role to grant access to the admin panel.')
                ->mutateDataUsing(fn (array $data): array => $data + ['must_change_password' => true]),
        ];
    }
}
