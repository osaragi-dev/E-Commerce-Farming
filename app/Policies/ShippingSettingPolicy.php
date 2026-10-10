<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ShippingSetting;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ShippingSettingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ShippingSetting');
    }

    public function view(AuthUser $authUser, ShippingSetting $shippingSetting): bool
    {
        return $authUser->can('View:ShippingSetting');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ShippingSetting');
    }

    public function update(AuthUser $authUser, ShippingSetting $shippingSetting): bool
    {
        return $authUser->can('Update:ShippingSetting');
    }

    public function delete(AuthUser $authUser, ShippingSetting $shippingSetting): bool
    {
        return $authUser->can('Delete:ShippingSetting');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ShippingSetting');
    }

    public function restore(AuthUser $authUser, ShippingSetting $shippingSetting): bool
    {
        return $authUser->can('Restore:ShippingSetting');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ShippingSetting');
    }

    public function replicate(AuthUser $authUser, ShippingSetting $shippingSetting): bool
    {
        return $authUser->can('Replicate:ShippingSetting');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ShippingSetting');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:ShippingSetting');
    }
}
