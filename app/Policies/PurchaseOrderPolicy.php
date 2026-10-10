<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:PurchaseOrder');
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can('View:PurchaseOrder');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:PurchaseOrder');
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can('Update:PurchaseOrder');
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can('Delete:PurchaseOrder');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:PurchaseOrder');
    }

    public function restore(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->can('Restore:PurchaseOrder');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:PurchaseOrder');
    }

    public function export(User $user): bool
    {
        return $user->can('Export:PurchaseOrder');
    }
}
