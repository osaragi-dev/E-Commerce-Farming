<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\WarehouseStock;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class WarehouseStockPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Stock');
    }

    public function view(AuthUser $authUser, WarehouseStock $warehouseStock): bool
    {
        return false;
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Stock') && $authUser->can('Export:Stock');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, WarehouseStock $warehouseStock): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, WarehouseStock $warehouseStock): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function restore(AuthUser $authUser, WarehouseStock $warehouseStock): bool
    {
        return false;
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return false;
    }

    public function replicate(AuthUser $authUser, WarehouseStock $warehouseStock): bool
    {
        return false;
    }

    public function reorder(AuthUser $authUser): bool
    {
        return false;
    }
}
