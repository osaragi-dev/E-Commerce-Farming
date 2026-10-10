<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockWaste;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StockWastePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockWaste');
    }

    public function view(AuthUser $authUser, StockWaste $stockWaste): bool
    {
        return $authUser->can('View:StockWaste');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockWaste');
    }

    public function update(AuthUser $authUser, StockWaste $stockWaste): bool
    {
        return $authUser->can('Update:StockWaste');
    }

    public function delete(AuthUser $authUser, StockWaste $stockWaste): bool
    {
        return $authUser->can('Delete:StockWaste');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StockWaste');
    }

    public function restore(AuthUser $authUser, StockWaste $stockWaste): bool
    {
        return $authUser->can('Restore:StockWaste');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StockWaste');
    }

    public function replicate(AuthUser $authUser, StockWaste $stockWaste): bool
    {
        return $authUser->can('Replicate:StockWaste');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StockWaste');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:StockWaste');
    }
}
