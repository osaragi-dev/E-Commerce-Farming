<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CompanyProfile;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CompanyProfilePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CompanyProfile');
    }

    public function update(AuthUser $authUser, CompanyProfile $companyProfile): bool
    {
        return $authUser->can('Update:CompanyProfile');
    }
}
