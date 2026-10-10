<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockMutation;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StockMutationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockMutation');
    }

    public function view(AuthUser $authUser, StockMutation $stockMutation): bool
    {
        return $authUser->can('View:StockMutation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockMutation');
    }

    public function update(AuthUser $authUser, StockMutation $stockMutation): bool
    {
        return $authUser->can('Update:StockMutation');
    }

    public function delete(AuthUser $authUser, StockMutation $stockMutation): bool
    {
        return $authUser->can('Delete:StockMutation');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StockMutation');
    }

    public function restore(AuthUser $authUser, StockMutation $stockMutation): bool
    {
        return $authUser->can('Restore:StockMutation');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StockMutation');
    }

    public function replicate(AuthUser $authUser, StockMutation $stockMutation): bool
    {
        return $authUser->can('Replicate:StockMutation');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StockMutation');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:StockMutation');
    }
}
