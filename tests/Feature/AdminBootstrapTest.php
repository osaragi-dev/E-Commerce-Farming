<?php

use App\Actions\AuthenticateBackOfficeUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('non-interactive bootstrap refuses to accept plaintext credentials from options', function (): void {
    $this->artisan('app:create-super-admin', ['--no-interaction' => true])
        ->expectsOutputToContain('Run this command interactively')->assertFailed();
    expect(User::count())->toBe(0);
});

test('interactive bootstrap creates a local administrator with a hashed password', function (): void {
    $this->artisan('app:create-super-admin', ['--name' => 'Owner', '--email' => 'owner@example.test'])
        ->expectsQuestion('Password', 'temporary-owner-password')
        ->expectsQuestion('Confirm password', 'temporary-owner-password')->assertSuccessful();
    $user = User::where('email', 'owner@example.test')->sole();
    expect($user->hasRole('super_admin'))->toBeTrue()
        ->and(Hash::check('temporary-owner-password', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()->and($user->sso_id)->toBeNull();
});

test('bootstrap rejects short passwords without creating an account', function (): void {
    $this->artisan('app:create-super-admin', ['--name' => 'Owner', '--email' => 'owner@example.test'])
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')->assertFailed();
    expect(User::count())->toBe(0);
});

test('bootstrap safely sets a local password on an existing SSO administrator', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::factory()->create(['name' => 'super_admin']));
    $id = $user->id;
    $this->artisan('app:create-super-admin', ['--email' => $user->email, '--reset-password' => true])
        ->expectsQuestion('Password', 'new-local-password')
        ->expectsQuestion('Confirm password', 'new-local-password')->assertSuccessful();
    expect($user->refresh()->id)->toBe($id)
        ->and(Hash::check('new-local-password', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()->and(User::count())->toBe(1);
});

test('an existing email is never modified without the explicit reset option', function (): void {
    $user = User::factory()->create(['is_active' => false]);
    $hash = $user->password;
    $this->artisan('app:create-super-admin', ['--email' => $user->email])->assertFailed();
    expect($user->refresh()->is_active)->toBeFalse()->and($user->password)->toBe($hash)
        ->and($user->roles)->toBeEmpty();
});

test('bootstrap never activates or promotes an ineligible existing account', function (string $restriction): void {
    $user = User::factory()->create(['is_active' => $restriction !== 'inactive']);
    if ($restriction !== 'no role') {
        $user->assignRole(Role::factory()->create(['name' => 'super_admin']));
    }
    if ($restriction === 'deleted') {
        $user->delete();
    }
    if ($restriction === 'draft') {
        DB::table('users')->where('id', $user->id)->update(['record_status' => 'draft']);
    }
    if ($restriction === 'customer') {
        $user->assignRole(Role::factory()->create(['name' => 'customer']));
    }
    $hash = $user->password;
    $this->artisan('app:create-super-admin', ['--email' => $user->email, '--reset-password' => true])->assertFailed();
    expect($user->refresh()->password)->toBe($hash);
})->with(['inactive', 'deleted', 'no role', 'draft', 'customer']);

test('terminal reset preserves custom internal roles and enables local login', function (): void {
    $user = User::factory()->sso()->create();
    $role = Role::factory()->create(['name' => 'Admin Farming']);
    $user->assignRole($role);
    $rememberToken = $user->remember_token;

    $this->artisan('app:create-super-admin', ['--email' => $user->email, '--reset-password' => true])
        ->expectsQuestion('Password', 'new-local-password')
        ->expectsQuestion('Confirm password', 'new-local-password')
        ->assertSuccessful();

    expect($user->refresh()->roles->modelKeys())->toBe([$role->id])
        ->and($user->remember_token)->not->toBe($rememberToken)
        ->and($user->must_change_password)->toBeTrue()
        ->and(Role::where('name', 'super_admin')->exists())->toBeFalse()
        ->and(app(AuthenticateBackOfficeUser::class)->execute($user->email, 'new-local-password')->id)->toBe($user->id);
});
