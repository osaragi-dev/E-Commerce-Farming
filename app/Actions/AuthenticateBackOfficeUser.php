<?php

namespace App\Actions;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class AuthenticateBackOfficeUser
{
    public function execute(string $login, #[SensitiveParameter] string $password): User
    {
        return app(Timebox::class)->call(function (Timebox $timebox) use ($login, $password): User {
            $user = User::query()->whereRaw('lower(email) = ?', [Str::lower(trim($login))])->first();

            if ($user === null || ! Hash::check($password, $user->password ?? '')
                || ! $user->canAccessPanel(Filament::getPanel('admin'))) {
                $this->deny();
            }

            if (Hash::needsRehash($user->password)) {
                $user->forceFill(['password' => $password])->save();
            }

            $timebox->returnEarly();

            return $user;
        }, 200000);
    }

    private function deny(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
