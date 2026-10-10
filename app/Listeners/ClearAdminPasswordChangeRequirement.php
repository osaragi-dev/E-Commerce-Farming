<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

class ClearAdminPasswordChangeRequirement
{
    public function handle(PasswordReset $event): void
    {
        if ($event->user instanceof User && $event->user->requiresPasswordChange()) {
            $event->user->forceFill(['must_change_password' => false])->save();
        }
    }
}
