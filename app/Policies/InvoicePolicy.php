<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Invoice');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('View:Invoice');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Invoice');
    }

    public function export(User $user, Invoice $invoice): bool
    {
        return $user->can('View:Invoice') && $user->can('Export:Invoice');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->can('Delete:Invoice');
    }
}
