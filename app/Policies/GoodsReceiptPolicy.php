<?php

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;

class GoodsReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:GoodsReceipt');
    }

    public function view(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $user->can('View:GoodsReceipt');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:GoodsReceipt');
    }

    public function update(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $user->can('Update:GoodsReceipt');
    }

    public function delete(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $user->can('Delete:GoodsReceipt');
    }

    public function restore(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $user->can('Restore:GoodsReceipt');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:GoodsReceipt');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:GoodsReceipt');
    }

    public function export(User $user): bool
    {
        return $user->can('Export:GoodsReceipt');
    }
}
