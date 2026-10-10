<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('a new Google customer is authenticated on the first callback without a User row', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token']),
        'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
            'sub' => 'google-new-customer',
            'name' => 'Google Customer',
            'email' => 'google-customer@example.com',
            'email_verified' => true,
        ]),
    ]);

    $this->withSession(['google_oauth_state' => 'test-state'])
        ->get(route('auth.google.callback', ['code' => 'test-code', 'state' => 'test-state']))
        ->assertRedirect(route('home'));

    $customer = Customer::query()->where('email', 'google-customer@example.com')->sole();

    $this->assertAuthenticatedAs($customer, 'customer');
    expect($customer->is_active)->toBeTrue()
        ->and($customer->google_id)->toBe('google-new-customer')
        ->and($customer->hasVerifiedEmail())->toBeTrue()
        ->and(User::query()->where('email', $customer->email)->exists())->toBeFalse();
    Http::assertSentCount(2);
});

test('an inactive Google customer cannot log in', function (): void {
    $customer = Customer::factory()->create([
        'google_id' => 'google-inactive-customer',
        'is_active' => false,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token']),
        'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
            'sub' => $customer->google_id,
            'name' => $customer->name,
            'email' => $customer->email,
            'email_verified' => true,
        ]),
    ]);

    $this->withSession(['google_oauth_state' => 'test-state'])
        ->get(route('auth.google.callback', ['code' => 'test-code', 'state' => 'test-state']))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Akun kamu sedang dinonaktifkan. Hubungi admin.');

    $this->assertGuest('customer');
    expect($customer->refresh()->is_active)->toBeFalse();
    Http::assertSentCount(2);
});

test('Google login upgrades a matching manual customer profile', function (): void {
    $customer = Customer::factory()->create([
        'email' => 'manual-google@example.com',
        'phone' => '08123456789',
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-token']),
        'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
            'sub' => 'google-manual-customer',
            'name' => 'Manual Google Customer',
            'email' => $customer->email,
            'email_verified' => true,
        ]),
    ]);

    $this->withSession(['google_oauth_state' => 'test-state'])
        ->get(route('auth.google.callback', ['code' => 'test-code', 'state' => 'test-state']))
        ->assertRedirect(route('home'));

    expect(Customer::query()->count())->toBe(1)
        ->and($customer->refresh()->google_id)->toBe('google-manual-customer')
        ->and($customer->accountStatusLabel())->toBe('Registered');
});
