<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Item');
    }

    public function view(User $user, Item $item): bool
    {
        return $user->can('View:Item');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Item');
    }

    public function update(User $user, Item $item): bool
    {
        return $user->can('Update:Item');
    }

    public function delete(User $user, Item $item): bool
    {
        return $user->can('Delete:Item');
    }

    public function restore(User $user, Item $item): bool
    {
        return $user->can('Restore:Item');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Item');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:Item');
    }

    public function replicate(User $user, Item $item): bool
    {
        return $user->can('Replicate:Item');
    }

    public function reorder(User $user): bool
    {
        return $user->can('Reorder:Item');
    }

    public function export(User $user): bool
    {
        return $user->can('Export:Item');
    }
}
