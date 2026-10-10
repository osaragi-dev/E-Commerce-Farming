<?php

namespace App\Policies;

use App\Models\ApprovalSetting;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ApprovalSettingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ApprovalSetting');
    }

    public function view(AuthUser $authUser, ApprovalSetting $approvalSetting): bool
    {
        return $authUser->can('View:ApprovalSetting');
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, ApprovalSetting $approvalSetting): bool
    {
        return $authUser->can('Update:ApprovalSetting');
    }

    public function delete(AuthUser $authUser, ApprovalSetting $approvalSetting): bool
    {
        return false;
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:ApprovalSetting');
    }
}
