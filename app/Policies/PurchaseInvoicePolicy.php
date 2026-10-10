<?php

namespace App\Policies;

use App\Models\PurchaseInvoice;
use App\Models\User;

class PurchaseInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:PurchaseInvoice');
    }

    public function view(User $user, PurchaseInvoice $purchaseInvoice): bool
    {
        return $user->can('View:PurchaseInvoice');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:PurchaseInvoice');
    }

    public function update(User $user, PurchaseInvoice $purchaseInvoice): bool
    {
        return $user->can('Update:PurchaseInvoice');
    }

    public function delete(User $user, PurchaseInvoice $purchaseInvoice): bool
    {
        return $user->can('Delete:PurchaseInvoice');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:PurchaseInvoice');
    }

    public function restore(User $user, PurchaseInvoice $purchaseInvoice): bool
    {
        return $user->can('Restore:PurchaseInvoice');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:PurchaseInvoice');
    }

    public function export(User $user): bool
    {
        return $user->can('Export:PurchaseInvoice');
    }
}
