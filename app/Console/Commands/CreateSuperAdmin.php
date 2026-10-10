<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('app:create-super-admin {--name=} {--email=} {--reset-password : Set a new password for an existing active administrator}')]
#[Description('Create a local administrator or reset an existing administrator password securely')]
class CreateSuperAdmin extends Command
{
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter a password securely.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $user = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null && ! $this->option('reset-password')) {
            $this->error('The account already exists. Use --reset-password to change its password without changing its roles.');

            return self::FAILURE;
        }

        if ($user !== null && (! $user->canAccessPanel(Filament::getPanel('admin')) || $user->hasRole('customer'))) {
            $this->error('The account was not changed. Password reset requires an active, saved internal account with panel access.');

            return self::FAILURE;
        }

        if ($user === null && $this->option('reset-password')) {
            $this->error('No administrator exists with this email.');

            return self::FAILURE;
        }

        $data = [
            'name' => $user?->name ?? ($this->option('name') ?: $this->ask('Name')),
            'email' => $email,
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $data): void {
            $administrator = $user ?? new User([
                'name' => $data['name'], 'email' => $data['email'], 'is_active' => true,
            ]);
            $administrator->forceFill([
                'password' => $data['password'],
                'must_change_password' => true,
                'remember_token' => Str::random(60),
            ])->save();
            if ($user === null) {
                $administrator->assignRole(Role::findOrCreate('super_admin', 'web'));
            }
        });

        $this->info('Local administrator ready. Change the password after signing in.');

        return self::SUCCESS;
    }
}
