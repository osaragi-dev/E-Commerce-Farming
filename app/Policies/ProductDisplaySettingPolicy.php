<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductDisplaySetting;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductDisplaySettingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductDisplaySetting');
    }

    public function view(AuthUser $authUser, ProductDisplaySetting $productDisplaySetting): bool
    {
        return $authUser->can('View:ProductDisplaySetting');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductDisplaySetting');
    }

    public function update(AuthUser $authUser, ProductDisplaySetting $productDisplaySetting): bool
    {
        return $authUser->can('Update:ProductDisplaySetting');
    }

    public function delete(AuthUser $authUser, ProductDisplaySetting $productDisplaySetting): bool
    {
        return $authUser->can('Delete:ProductDisplaySetting');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProductDisplaySetting');
    }

    public function restore(AuthUser $authUser, ProductDisplaySetting $productDisplaySetting): bool
    {
        return $authUser->can('Restore:ProductDisplaySetting');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductDisplaySetting');
    }

    public function replicate(AuthUser $authUser, ProductDisplaySetting $productDisplaySetting): bool
    {
        return $authUser->can('Replicate:ProductDisplaySetting');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductDisplaySetting');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:ProductDisplaySetting');
    }
}
