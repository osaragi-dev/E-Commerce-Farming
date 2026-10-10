<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Override;

class EditRole extends EditRecord
{
    public Collection $permissions;

    protected static string $resource = RoleResource::class;

    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['guard_name'] = Utils::getFilamentAuthGuard();

        $this->permissions = collect($data)
            ->filter(fn (mixed $permission, string $key): bool => ! in_array($key, ['code', 'name', 'guard_name', 'select_all', Utils::getTenantModelForeignKey()], true))
            ->values()
            ->flatten()
            ->unique();

        if (Utils::isTenancyEnabled() && Arr::has($data, Utils::getTenantModelForeignKey()) && filled($data[Utils::getTenantModelForeignKey()])) {
            return Arr::only($data, ['code', 'name', 'guard_name', Utils::getTenantModelForeignKey()]);
        }

        return Arr::only($data, ['code', 'name', 'guard_name']);
    }

    protected function afterSave(): void
    {
        $existingPermissionNames = $this->record
            ->getPermissionNames()
            ->sort()
            ->values()
            ->all();
        $requestedPermissionNames = $this->permissions
            ->sort()
            ->values()
            ->all();

        $permissionModels = collect();
        $this->permissions->each(function (string $permission) use ($permissionModels): void {
            $permissionModels->push(Utils::getPermissionModel()::firstOrCreate([
                'name' => $permission,
                'guard_name' => $this->record->guard_name,
            ]));
        });

        // @phpstan-ignore-next-line
        $this->record->syncPermissions($permissionModels);

        if ($existingPermissionNames !== $requestedPermissionNames) {
            $this->record->touch();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
