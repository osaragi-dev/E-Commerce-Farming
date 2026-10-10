<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use STS\FilamentImpersonate\Events\EnterImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

class LogImpersonation
{
    public function handle(EnterImpersonation|LeaveImpersonation $event): void
    {
        Log::info($event instanceof EnterImpersonation ? 'impersonation_entered' : 'impersonation_left', [
            'actor_id' => $event->impersonator?->getAuthIdentifier(),
            'target_user_id' => $event->impersonated?->getAuthIdentifier(),
            'guard' => 'web',
        ]);
    }
}
