<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockOpnameSession;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StockOpnameSessionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockOpnameSession');
    }

    public function view(AuthUser $authUser, StockOpnameSession $stockOpname): bool
    {
        return $authUser->can('View:StockOpnameSession');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockOpnameSession');
    }

    public function update(AuthUser $authUser, StockOpnameSession $stockOpname): bool
    {
        return $authUser->can('Update:StockOpnameSession');
    }

    public function delete(AuthUser $authUser, StockOpnameSession $stockOpname): bool
    {
        return $authUser->can('Delete:StockOpnameSession');
    }

    public function restore(AuthUser $authUser, StockOpnameSession $stockOpname): bool
    {
        return $authUser->can('Restore:StockOpnameSession');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:StockOpnameSession');
    }
}
