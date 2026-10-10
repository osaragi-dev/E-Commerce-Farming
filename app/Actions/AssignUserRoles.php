<?php

namespace App\Actions;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignUserRoles
{
    /** @param list<string> $roleIds */
    public function execute(User $user, array $roleIds): void
    {
        Gate::authorize('update', $user);

        if ($user->trashed() || $user->hasRole('customer')) {
            throw ValidationException::withMessages(['roles' => 'This account cannot receive internal roles.']);
        }

        $roleIds = array_values(array_unique($roleIds));
        $roles = Role::query()->where('guard_name', Role::DEFAULT_GUARD_NAME)
            ->where('name', '!=', 'customer')->whereKey($roleIds)->get();

        if ($roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages(['roles' => 'One or more selected roles are invalid.']);
        }

        $user->syncRoles($roles);
    }
}
