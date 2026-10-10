<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockOpname;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StockOpnamePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockOpname');
    }

    public function view(AuthUser $authUser, StockOpname $stockOpname): bool
    {
        return $authUser->can('View:StockOpname');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockOpname');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:StockOpname');
    }
}
