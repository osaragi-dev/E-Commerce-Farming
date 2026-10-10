<?php

use App\Actions\AssignUserRoles;
use App\Actions\AuthenticateBackOfficeUser;
use App\Enums\RecordStatus;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('local login keeps one identity and its assigned roles without SSO', function (): void {
    $user = User::factory()->create();
    $role = Role::factory()->create();
    $user->assignRole($role);
    $first = app(AuthenticateBackOfficeUser::class)->execute($user->email, 'password');
    $second = app(AuthenticateBackOfficeUser::class)->execute($user->email, 'password');
    expect($first->id)->toBe($second->id)->and($second->hasRole($role))->toBeTrue()
        ->and(User::count())->toBe(1)->and($second->password)->not->toBeNull();
    Http::assertNothingSent();
});

test('former SSO identities log in using a newly assigned local password', function (): void {
    $user = User::factory()->sso()->create(['password' => 'local-password']);
    $role = Role::factory()->create(['sso_id' => 43]);
    $user->assignRole($role);
    Livewire::test(Login::class)->fillForm(['login' => $user->email, 'password' => 'local-password'])
        ->call('authenticate')->assertHasNoFormErrors()->assertRedirect();
    $this->assertAuthenticatedAs($user, 'web');
    expect($role->refresh()->sso_id)->toBe(43);
    Http::assertNothingSent();
});

test('local login refuses excluded or deleted roles', function (string $restriction): void {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => $restriction === 'customer' ? 'customer' : 'Staff']);
    $user->assignRole($role);
    if ($restriction === 'deleted') {
        $role->delete();
    }
    if ($restriction === 'guard') {
        DB::table('roles')->where('id', $role->id)->update(['guard_name' => 'customer']);
    }
    expect(fn () => app(AuthenticateBackOfficeUser::class)->execute($user->email, 'password'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['customer', 'guard', 'deleted']);

test('local login cannot acquire an unassigned role by matching its name', function (): void {
    Role::factory()->create(['name' => 'super_admin']);
    $user = User::factory()->create();
    expect(fn () => app(AuthenticateBackOfficeUser::class)->execute($user->email, 'password'))
        ->toThrow(ValidationException::class);
    expect($user->refresh()->roles)->toBeEmpty();
});

test('local login rejects inactive and non-saved accounts', function (string $restriction): void {
    $user = User::factory()->create();
    $user->assignRole(Role::factory()->create());
    DB::table('users')->where('id', $user->id)->update($restriction === 'inactive'
        ? ['is_active' => false] : ['record_status' => RecordStatus::Draft->value]);
    expect(fn () => app(AuthenticateBackOfficeUser::class)->execute($user->email, 'password'))
        ->toThrow(ValidationException::class);
})->with(['inactive', 'draft']);

test('local login does not restore a deleted identity', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::factory()->create());
    $user->delete();
    expect(fn () => app(AuthenticateBackOfficeUser::class)->execute($user->email, 'password'))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->trashed())->toBeTrue();
});

test('unknown email cannot provision an account through local login', function (): void {
    Livewire::test(Login::class)->fillForm(['login' => 'missing@example.test', 'password' => 'test-password'])
        ->call('authenticate')->assertHasFormErrors(['login']);
    $this->assertGuest('web');
    expect(User::count())->toBe(0);
});

test('former SSO users with no local password must reset it before login', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::factory()->create());
    Livewire::test(Login::class)->fillForm(['login' => $user->email, 'password' => 'test-password'])
        ->call('authenticate')->assertHasFormErrors(['login']);
    $this->assertGuest('web');
    expect($user->refresh()->password)->toBeNull();
    Http::assertNothingSent();
});

test('local login rejects incorrect credentials even for administrators', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::factory()->create(['name' => 'super_admin']));
    Livewire::test(Login::class)->fillForm(['login' => $user->email, 'password' => 'wrong-password'])
        ->call('authenticate')->assertHasFormErrors(['login']);
    $this->assertGuest('web');
    Http::assertNothingSent();
});

test('assigning roles replaces local membership without a remote request', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::factory()->create(['name' => 'super_admin']));
    $this->actingAs($administrator);
    $user = User::factory()->create();
    $user->assignRole(Role::factory()->create());
    $new = Role::factory()->create();
    app(AssignUserRoles::class)->execute($user, [$new->id]);
    expect($user->fresh()->getRoleNames()->all())->toBe([$new->name]);
    Http::assertNothingSent();
});

test('invalid role assignment preserves local membership', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::factory()->create(['name' => 'super_admin']));
    $this->actingAs($administrator);
    $user = User::factory()->create();
    $old = Role::factory()->create();
    $user->assignRole($old);
    expect(fn () => app(AssignUserRoles::class)->execute($user, [(string) Str::uuid7()]))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->getRoleNames()->all())->toBe([$old->name]);
});

test('role assignment rejects unauthorized actors', function (): void {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();
    expect(fn () => app(AssignUserRoles::class)->execute($user, []))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

test('role creation succeeds without SSO availability', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::factory()->create(['name' => 'super_admin']));
    $this->actingAs($administrator);
    Livewire::test(CreateRole::class)->fillForm(['code' => 'AUDIT', 'name' => 'Auditor'])
        ->call('create')->assertHasNoFormErrors();
    expect(Role::where('code', 'AUDIT')->sole()->sso_id)->toBeNull();
    Http::assertNothingSent();
});

test('role updates preserve former identity links without consulting SSO', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::factory()->create(['name' => 'super_admin']));
    $this->actingAs($administrator);
    $role = Role::factory()->create(['sso_id' => 20, 'code' => 'AUDIT', 'name' => 'Auditor']);
    Livewire::test(EditRole::class, ['record' => $role->id])->fillForm(['name' => 'Manager'])
        ->call('save')->assertHasNoFormErrors();
    expect($role->fresh()->name)->toBe('Manager')->and($role->fresh()->sso_id)->toBe(20);
    Http::assertNothingSent();
});

test('retired SSO commands cannot change local role metadata', function (): void {
    $commands = Artisan::all();
    expect($commands)->not->toHaveKey('roles:sso-sync')->not->toHaveKey('roles:sso-bootstrap');
    $events = app(Schedule::class)->events();
    expect(collect($events)->contains(fn ($event): bool => str_contains($event->command ?? '', 'roles:sso')))->toBeFalse();
});

test('password reset provisions a local password for a former SSO user', function (): void {
    $user = User::factory()->sso()->create(['must_change_password' => true]);
    $user->assignRole(Role::factory()->create());
    $token = Password::broker('users')->createToken($user);
    Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
        ->fillForm(['password' => 'new-local-secret', 'passwordConfirmation' => 'new-local-secret'])
        ->call('resetPassword')->assertHasNoFormErrors()->assertRedirect();
    expect(Hash::check('new-local-secret', $user->refresh()->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse();
    expect(app(AuthenticateBackOfficeUser::class)->execute($user->email, 'new-local-secret')->id)->toBe($user->id);
    Http::assertNothingSent();
});

test('empty local role replacement revokes access', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole(Role::factory()->create(['name' => 'super_admin']));
    $this->actingAs($administrator);
    $user = User::factory()->create();
    $user->assignRole(Role::factory()->create());
    app(AssignUserRoles::class)->execute($user, []);
    expect($user->fresh()->roles)->toBeEmpty();
    expect(fn () => app(AuthenticateBackOfficeUser::class)->execute($user->email, 'password'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

test('ineligible admin sessions can reach login without logging out a customer', function (): void {
    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer')->actingAs($user, 'web');
    $this->get(route('filament.admin.auth.login'))->assertOk()->assertSee('Email');
    $this->assertGuest('web');
    $this->assertAuthenticatedAs($customer, 'customer');
});

test('custom admin routes reject ineligible sessions and enforce required password change', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    $url = route('admin.invoices.preview', ['token' => Str::uuid()->toString()]);
    $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
    $user->assignRole(Role::factory()->create(['name' => 'super_admin']));
    $user->forceFill(['must_change_password' => true])->save();
    $this->get($url)->assertRedirect(ChangePassword::getUrl(panel: 'admin'));
});

test('eligible internal users receive a local password reset link', function (): void {
    Notification::fake();
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::factory()->create());
    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $user->email])->call('request');
    Notification::assertSentTo($user, ResetPasswordNotification::class,
        fn (ResetPasswordNotification $notification): bool => str_contains($notification->url, '/admin/password-reset/reset'));
    Http::assertNothingSent();
});

test('password reset does not notify inactive or unauthorized accounts', function (bool $active): void {
    Notification::fake();
    $user = User::factory()->create(['is_active' => $active]);
    if (! $active) {
        $user->assignRole(Role::factory()->create());
    }
    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $user->email])->call('request');
    Notification::assertNothingSent();
})->with([true, false]);

test('invalid reset tokens cannot replace a local password', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::factory()->create());
    $hash = $user->password;
    Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => 'invalid-token'])
        ->fillForm(['password' => 'new-local-secret', 'passwordConfirmation' => 'new-local-secret'])
        ->call('resetPassword')->assertNoRedirect();
    expect($user->refresh()->password)->toBe($hash);
});

test('admin login rate limits repeated password guesses', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::factory()->create());
    $component = Livewire::test(Login::class)->fillForm(['login' => $user->email, 'password' => 'incorrect']);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $component->call('authenticate')->assertHasFormErrors(['login']);
    }
    $component->fillForm(['password' => 'password'])->call('authenticate')->assertNoRedirect();
    $this->assertGuest('web');
});
