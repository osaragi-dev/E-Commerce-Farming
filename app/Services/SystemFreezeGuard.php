<?php

namespace App\Services;

use App\Models\SystemFreezeState;
use Closure;
use Illuminate\Validation\ValidationException;

final class SystemFreezeGuard
{
    private static int $publicOrderCreationDepth = 0;

    public function isFrozen(): bool
    {
        return SystemFreezeState::current()->is_frozen;
    }

    public function assertNotFrozen(): void
    {
        if (! $this->isFrozen() || self::$publicOrderCreationDepth > 0) {
            return;
        }

        throw ValidationException::withMessages([
            'system_freeze' => 'The system is temporarily frozen while stock opname is in progress.',
        ]);
    }

    /**
     * Allow only the authenticated customer checkout write set during freeze.
     * The scope is always unwound, including when the transaction fails.
     *
     * @param  Closure(): mixed  $callback
     */
    public function runPublicOrderCreation(Closure $callback): mixed
    {
        if (! auth('customer')->check() || auth('web')->check()) {
            return $callback();
        }

        self::$publicOrderCreationDepth++;

        try {
            return $callback();
        } finally {
            self::$publicOrderCreationDepth = max(0, self::$publicOrderCreationDepth - 1);
        }
    }
}
