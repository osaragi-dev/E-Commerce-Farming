<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentSetting;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PaymentSettingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PaymentSetting');
    }

    public function view(AuthUser $authUser, PaymentSetting $paymentSetting): bool
    {
        return $authUser->can('View:PaymentSetting');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PaymentSetting');
    }

    public function update(AuthUser $authUser, PaymentSetting $paymentSetting): bool
    {
        return $authUser->can('Update:PaymentSetting');
    }

    public function delete(AuthUser $authUser, PaymentSetting $paymentSetting): bool
    {
        return $authUser->can('Delete:PaymentSetting');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PaymentSetting');
    }

    public function restore(AuthUser $authUser, PaymentSetting $paymentSetting): bool
    {
        return $authUser->can('Restore:PaymentSetting');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PaymentSetting');
    }

    public function replicate(AuthUser $authUser, PaymentSetting $paymentSetting): bool
    {
        return $authUser->can('Replicate:PaymentSetting');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PaymentSetting');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:PaymentSetting');
    }
}
