<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Unit;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class UnitPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Unit');
    }

    public function view(AuthUser $authUser, Unit $unit): bool
    {
        return $authUser->can('View:Unit');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Unit');
    }

    public function update(AuthUser $authUser, Unit $unit): bool
    {
        return $authUser->can('Update:Unit');
    }

    public function delete(AuthUser $authUser, Unit $unit): bool
    {
        return $authUser->can('Delete:Unit');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Unit');
    }

    public function restore(AuthUser $authUser, Unit $unit): bool
    {
        return $authUser->can('Restore:Unit');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Unit');
    }

    public function replicate(AuthUser $authUser, Unit $unit): bool
    {
        return $authUser->can('Replicate:Unit');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Unit');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:Unit');
    }
}
