<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

class VendorPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Vendor');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Vendor $vendor): bool
    {
        return $user->can('View:Vendor');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('Create:Vendor');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Vendor $vendor): bool
    {
        return $user->can('Update:Vendor');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Vendor $vendor): bool
    {
        return $user->can('Delete:Vendor');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Vendor $vendor): bool
    {
        return $user->can('Restore:Vendor');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Vendor');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:Vendor');
    }

    public function replicate(User $user, Vendor $vendor): bool
    {
        return $user->can('Replicate:Vendor');
    }

    public function reorder(User $user): bool
    {
        return $user->can('Reorder:Vendor');
    }

    public function export(User $user): bool
    {
        return $user->can('Export:Vendor');
    }
}
