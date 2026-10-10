<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Payment');
    }

    public function view(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('View:Payment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Payment');
    }

    public function update(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('Update:Payment');
    }

    public function delete(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('Delete:Payment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Payment');
    }

    public function restore(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('Restore:Payment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Payment');
    }

    public function replicate(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('Replicate:Payment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Payment');
    }

    public function export(AuthUser $authUser): bool
    {
        return $authUser->can('Export:Payment');
    }

    /**
     * Custom permission — see RoleSeeder::CUSTOM_PERMISSIONS. Kept separate
     * from Update:Payment on purpose (confirming/rejecting/voiding a
     * payment is a financial-verification action, distinct from editing a
     * payment record).
     *
     * ⚠️ IMPORTANT: see OrderPolicy::advanceStatus()'s warning — this
     * method (and reject()/void() below) is NOT known to
     * `php artisan shield:generate` and is silently WIPED every time this
     * file gets regenerated (which happens any time RoleSeeder runs). The
     * Actions that call ->authorize() for these abilities use the raw
     * permission string directly (not these method names) specifically so
     * they keep working even if these methods are wiped again — see
     * VerifyPaymentAction::make() etc.
     */
    public function verify(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('Verify:Payment');
    }

    public function reject(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('Reject:Payment');
    }

    public function void(AuthUser $authUser, Payment $payment): bool
    {
        return $authUser->can('Void:Payment');
    }
}
