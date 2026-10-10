<?php

use App\Filament\Pages\ChangePassword;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PasswordResetVerificationCode;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

test('unverified customers can render the email verification notice', function (): void {
    $customer = Customer::factory()->unverified()->registered()->create();

    $this->actingAs($customer, 'customer')
        ->get(route('verification.notice'))
        ->assertSee('Verify Your Email')
        ->assertSee('Resend Verification Email')
        ->assertSeeHtml('<form method="POST" action="'.route('verification.send').'">')
        ->assertDontSee('&lt;form', false);
});

test('customer registration creates a Customer account without a User row', function (): void {
    Notification::fake();

    Livewire::test(Register::class)
        ->set('name', 'Budi Santoso')
        ->set('email', 'budi@example.com')
        ->set('phone', '08123456789')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertRedirect(route('verification.notice'));

    $customer = Customer::query()->where('email', 'budi@example.com')->sole();

    expect($customer->hasLoginAccount())->toBeTrue()
        ->and(auth('customer')->id())->toBe($customer->id)
        ->and(User::query()->where('email', 'budi@example.com')->exists())->toBeFalse();

    Notification::assertSentTo($customer, VerifyEmail::class);
});

test('registration upgrades a matching manual customer profile without duplicating it', function (): void {
    Notification::fake();

    $customer = Customer::factory()->create([
        'name' => 'Manual Customer',
        'email' => 'manual@example.com',
        'phone' => '08123456789',
        'is_active' => false,
    ]);

    Livewire::test(Register::class)
        ->set('name', 'Registered Customer')
        ->set('email', 'manual@example.com')
        ->set('phone', '08123456789')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertRedirect(route('verification.notice'));

    expect(Customer::query()->count())->toBe(1)
        ->and($customer->refresh()->name)->toBe('Registered Customer')
        ->and($customer->is_active)->toBeTrue()
        ->and($customer->hasLoginAccount())->toBeTrue();
});

test('customer registration ignores a stale web session actor', function (): void {
    Notification::fake();

    $legacyUser = User::factory()->create();
    $this->actingAs($legacyUser, 'web');

    $customer = Customer::factory()->create([
        'email' => 'stale-session@example.com',
        'phone' => '08123456789',
    ]);
    $customer->delete();
    $legacyUser->delete();

    Auth::guard('web')->setUser($legacyUser);

    expect(auth('web')->id())->toBe($legacyUser->id);

    Livewire::test(Register::class)
        ->set('name', 'Recovered Customer')
        ->set('email', 'stale-session@example.com')
        ->set('phone', '08123456789')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertRedirect(route('verification.notice'));

    $customer->refresh();
    $restoreLog = $customer->statusLogs()->where('event', 'restored')->sole();

    expect($customer->hasLoginAccount())->toBeTrue()
        ->and($customer->updated_by)->toBeNull()
        ->and($restoreLog->changed_by_user_id)->toBeNull();
});

test('duplicate internal email cannot register as a customer', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    Livewire::test(Register::class)
        ->set('name', 'Test')
        ->set('email', 'ada@example.com')
        ->set('phone', '08123')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasErrors(['email']);
});

test('customer login redirects to home', function (): void {
    $customer = Customer::factory()->registered()->create(['password' => 'password123']);

    Livewire::test(Login::class)
        ->set('email', $customer->email)
        ->set('password', 'password123')
        ->call('login')
        ->assertRedirect(route('home'));
});

test('unverified customer login redirects to email verification', function (): void {
    Notification::fake();
    $customer = Customer::factory()->unverified()->create(['password' => 'password123']);

    Livewire::test(Login::class)
        ->set('email', $customer->email)
        ->set('password', 'password123')
        ->call('login')
        ->assertRedirect(route('verification.notice'));

    Notification::assertSentTo($customer, VerifyEmail::class);
});

test('customer can verify email with a signed link', function (): void {
    $customer = Customer::factory()->unverified()->registered()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
        'id' => $customer->getKey(),
        'hash' => sha1($customer->getEmailForVerification()),
    ]);

    $this->actingAs($customer, 'customer')
        ->get($url)
        ->assertRedirect(route('home'));

    expect($customer->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('customer can verify email from a device without an active session', function (): void {
    $customer = Customer::factory()->unverified()->registered()->create();

    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
        'id' => $customer->getKey(),
        'hash' => sha1($customer->getEmailForVerification()),
    ]);

    $this->get($url)->assertRedirect(route('login'));

    expect($customer->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('storefront login rejects local administrator credentials', function (): void {
    $user = User::factory()->create(['password' => 'password123']);
    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password123')
        ->call('login')
        ->assertHasErrors(['email']);
    $this->assertGuest('web');
});

test('admin users who must change their password are redirected to the password change page', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => true,
    ]);

    $passwordChangeUrl = ChangePassword::getUrl(panel: 'admin');

    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $this->actingAs($user, 'web')
        ->get('/admin')
        ->assertRedirect($passwordChangeUrl);

    $this->get($passwordChangeUrl)
        ->assertOk()
        ->assertSee('Current Password')
        ->assertSee('New Password')
        ->assertSee('Confirm New Password');

    $this->get('/admin/users')
        ->assertRedirect($passwordChangeUrl);
});

test('admin users can change their password and clear the first-login requirement', function (): void {
    Http::preventStrayRequests();

    Filament::setCurrentPanel('admin');

    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => true,
    ]);

    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $this->actingAs($user, 'web');

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('save')
        ->assertRedirect('/admin');

    $user->refresh();

    expect($user->requiresPasswordChange())->toBeFalse()
        ->and(Hash::check('new-password', $user->password))->toBeTrue()
        ->and($user->updated_by)->toBe($user->id);
    Http::assertNothingSent();
});

test('admin password change validates the current password, length, difference, and confirmation', function (): void {
    Http::preventStrayRequests();

    Filament::setCurrentPanel('admin');

    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => true,
    ]);

    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $this->actingAs($user, 'web');

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('save')
        ->assertHasFormErrors(['current_password']);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'old-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'old-password',
            'password' => 'old-password',
            'password_confirmation' => 'old-password',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect($user->refresh()->requiresPasswordChange())->toBeTrue();
    Http::assertNothingSent();
});

test('existing admin users without a password change requirement can access the panel', function (): void {
    Filament::setCurrentPanel('admin');

    $user = User::factory()->create(['must_change_password' => false]);

    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $this->actingAs($user, 'web')
        ->get('/admin')
        ->assertOk();
});

test('admin login offers email credentials and local password reset', function (): void {
    expect(Filament::getPanel('admin')->hasPasswordReset())->toBeTrue();
    $this->get('/admin/login')->assertOk()->assertSee('Email');
    $this->get('/admin/password-reset/request')->assertOk();
});

test('local password change works without a first-login requirement', function (): void {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => false,
    ]);
    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $hash = $user->password;
    $this->actingAs($user, 'web');
    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors()->assertRedirect('/admin');
    expect($user->refresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('new-password', $user->password))->toBeTrue();
    Http::assertNothingSent();
});

test('local password change rejects incorrect current credentials', function (): void {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => true,
    ]);
    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $hash = $user->password;
    $this->actingAs($user, 'web');
    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('save')
        ->assertHasFormErrors(['current_password']);
    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and($user->password)->toBe($hash);
    Http::assertNothingSent();
});

test('local password change rejects users without panel access', function (): void {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => true,
    ]);
    $hash = $user->password;
    $this->actingAs($user, 'web');
    Livewire::test(ChangePassword::class)->assertForbidden();
    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and($user->password)->toBe($hash);
    Http::assertNothingSent();
});

test('local password change requires the current password', function (): void {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => true,
    ]);
    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $hash = $user->password;
    $this->actingAs($user, 'web');
    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => '',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('save')
        ->assertHasFormErrors(['current_password']);
    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and($user->password)->toBe($hash);
    Http::assertNothingSent();
});

test('local password change preserves credentials for a short new password', function (): void {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => true,
    ]);
    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $hash = $user->password;
    $this->actingAs($user, 'web');
    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'old-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);
    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and($user->password)->toBe($hash);
    Http::assertNothingSent();
});

test('local password change preserves credentials for mismatched confirmation', function (): void {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => true,
    ]);
    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $hash = $user->password;
    $this->actingAs($user, 'web');
    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);
    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and($user->password)->toBe($hash);
    Http::assertNothingSent();
});

test('local password change rejects reuse of the current password', function (): void {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $user = User::factory()->create([
        'password' => 'old-password',
        'must_change_password' => true,
    ]);
    $user->assignRole(Role::findOrCreate('staff', 'web'));
    $hash = $user->password;
    $this->actingAs($user, 'web');
    Livewire::test(ChangePassword::class)
        ->fillForm([
            'current_password' => 'old-password',
            'password' => 'old-password',
            'password_confirmation' => 'old-password',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);
    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and($user->password)->toBe($hash);
    Http::assertNothingSent();
});

test('wrong password shows error', function (): void {
    $customer = Customer::factory()->registered()->create(['password' => 'password123']);

    Livewire::test(Login::class)
        ->set('email', $customer->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors(['email']);
});

test('forgot password updates the customer password that can be used to log in', function (): void {
    Notification::fake();

    $customer = Customer::factory()->registered()->create([
        'email' => 'forgot@example.com',
        'password' => 'old-password',
    ]);

    $component = Livewire::test(ForgotPassword::class)
        ->set('email', $customer->email)
        ->call('findAccount');

    $code = null;
    Notification::assertSentTo($customer, PasswordResetVerificationCode::class, function (PasswordResetVerificationCode $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    $component
        ->set('verificationCode', $code)
        ->call('verifyEmail')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('resetPassword')
        ->assertRedirect(route('login'));

    expect(Hash::check('new-password', $customer->refresh()->password))->toBeTrue();

    Livewire::test(Login::class)
        ->set('email', $customer->email)
        ->set('password', 'new-password')
        ->call('login')
        ->assertRedirect(route('home'));
});

test('forgot password cannot change the password before email verification', function (): void {
    Notification::fake();

    $customer = Customer::factory()->registered()->create([
        'email' => 'protected@example.com',
        'password' => 'old-password',
    ]);

    Livewire::test(ForgotPassword::class)
        ->set('email', $customer->email)
        ->call('findAccount')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('resetPassword')
        ->assertHasErrors(['verificationCode']);

    expect(Hash::check('old-password', $customer->refresh()->password))->toBeTrue();
});

test('customer accounts cannot access the admin panel', function (): void {
    $customer = Customer::factory()->registered()->create();

    expect(Filament::getPanel('admin')->getAuthGuard())->toBe('web')
        ->and($customer->getAuthIdentifier())->not->toBeNull();
});

test('register page keeps the cancel and Google registration options', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Cancel')
        ->assertSee('Sign Up with Google')
        ->assertSee(route('auth.google.redirect'), false)
        ->assertSee(route('home'), false);
});

test('login page has a cancel link to home', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Cancel')
        ->assertSee(route('home'), false);
});

test('forgot password page has a cancel link to login', function (): void {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('Cancel')
        ->assertSee(route('login'), false);
});

test('admin password change rate limits repeated current password guesses', function (): void {
    $user = User::factory()->create(['password' => 'current-password']);
    $user->assignRole(Role::factory()->create());
    $this->actingAs($user);
    $component = Livewire::test(ChangePassword::class)->fillForm([
        'current_password' => 'wrong-password', 'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $component->call('save')->assertHasFormErrors(['current_password']);
    }
    $component->fillForm(['current_password' => 'current-password'])
        ->call('save')->assertHasFormErrors(['current_password']);
    expect(Hash::check('current-password', $user->refresh()->password))->toBeTrue();
});
