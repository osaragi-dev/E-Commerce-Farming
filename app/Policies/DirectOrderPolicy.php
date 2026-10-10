<?php

namespace App\Policies;

use App\Models\DirectOrder;
use App\Models\User;

class DirectOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:DirectOrder');
    }

    public function view(User $user, DirectOrder $directOrder): bool
    {
        return $user->can('View:DirectOrder');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:DirectOrder');
    }

    public function update(User $user, DirectOrder $directOrder): bool
    {
        return $user->can('Update:DirectOrder');
    }

    public function delete(User $user, DirectOrder $directOrder): bool
    {
        return $user->can('Delete:DirectOrder');
    }

    public function restore(User $user, DirectOrder $directOrder): bool
    {
        return $user->can('Restore:DirectOrder');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:DirectOrder');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:DirectOrder');
    }

    public function replicate(User $user, DirectOrder $directOrder): bool
    {
        return $user->can('Replicate:DirectOrder');
    }

    public function reorder(User $user): bool
    {
        return $user->can('Reorder:DirectOrder');
    }

    public function export(User $user): bool
    {
        return $user->can('Export:DirectOrder');
    }
}
