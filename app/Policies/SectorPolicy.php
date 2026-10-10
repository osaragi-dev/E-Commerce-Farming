<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sector;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SectorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Sector');
    }

    public function view(AuthUser $authUser, Sector $sector): bool
    {
        return $authUser->can('View:Sector');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Sector');
    }

    public function update(AuthUser $authUser, Sector $sector): bool
    {
        return $authUser->can('Update:Sector');
    }

    public function delete(AuthUser $authUser, Sector $sector): bool
    {
        return $authUser->can('Delete:Sector');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Sector');
    }

    public function restore(AuthUser $authUser, Sector $sector): bool
    {
        return $authUser->can('Restore:Sector');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Sector');
    }

    public function replicate(AuthUser $authUser, Sector $sector): bool
    {
        return $authUser->can('Replicate:Sector');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Sector');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:Sector');
    }
}
