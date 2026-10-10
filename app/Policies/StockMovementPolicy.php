<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockMovement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StockMovementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockMovement');
    }

    public function view(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('View:StockMovement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockMovement');
    }

    public function update(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('Update:StockMovement');
    }

    public function delete(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('Delete:StockMovement');
    }

    public function restore(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('Restore:StockMovement');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:StockMovement');
    }
}
