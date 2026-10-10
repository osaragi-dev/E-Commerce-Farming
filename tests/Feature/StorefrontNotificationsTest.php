<?php

use App\Livewire\Public\NotificationsBell;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('clear all removes read and unread customer notifications including older items but preserves other accounts', function (): void {
    $customer = Customer::factory()->registered()->create();
    $other = Customer::factory()->registered()->create();
    $admin = User::factory()->create();

    foreach ([$customer, $other, $admin] as $owner) {
        for ($index = 0; $index < 10; $index++) {
            $owner->notifications()->create([
                'id' => (string) Str::uuid7(),
                'type' => 'test-notification',
                'data' => ['title' => 'Account notification'],
                'read_at' => $index < 2 ? now() : null,
            ]);
        }
    }

    $this->actingAs($customer, 'customer')->actingAs($admin, 'web');

    Livewire::test(NotificationsBell::class)
        ->call('toggle')
        ->assertSee('Clear all')
        ->call('clearAll')
        ->assertSee('No notifications yet.')
        ->assertSee('aria-label="Notifications"', false)
        ->assertDontSee('Clear all')
        ->assertDontSee('Show more notifications');

    expect($customer->notifications()->count())->toBe(0)
        ->and($other->notifications()->count())->toBe(10)
        ->and($admin->notifications()->count())->toBe(10);
});

test('storefront shows the same customer unread count on public and protected pages', function (): void {
    $customer = Customer::factory()->registered()->create();

    for ($index = 0; $index < 9; $index++) {
        $customer->notifications()->create([
            'id' => (string) Str::uuid7(),
            'type' => 'test-notification',
            'data' => ['title' => 'Customer notification'],
            'read_at' => $index < 2 ? now() : null,
        ]);
    }

    $this->actingAs($customer, 'customer');

    foreach (['home', 'account'] as $route) {
        Auth::shouldUse('web');
        $this->get(route($route))->assertOk()->assertSee('Notifications, 7 unread');
    }
});

test('customers can load notifications beyond the initial preview', function (): void {
    $customer = Customer::factory()->registered()->create();

    for ($index = 0; $index < 10; $index++) {
        $customer->notifications()->create([
            'id' => (string) Str::uuid7(),
            'type' => 'test-notification',
            'data' => ['title' => 'Customer notice '.$index],
            'created_at' => now()->subMinutes($index),
        ]);
    }

    Livewire::actingAs($customer, 'customer')->test(NotificationsBell::class)
        ->call('toggle')
        ->assertSee('Customer notice 7')
        ->assertDontSee('Customer notice 8')
        ->assertSee('Show more notifications')
        ->call('loadMore')
        ->assertSee('Customer notice 9')
        ->assertDontSee('Show more notifications');
});

test('notification read actions only update the signed in customer notifications', function (): void {
    $customer = Customer::factory()->registered()->create();
    $other = Customer::factory()->registered()->create();
    $admin = User::factory()->create();
    $notifications = collect([$customer, $other, $admin])->map(fn ($owner) => $owner->notifications()->create([
        'id' => (string) Str::uuid7(),
        'type' => 'test-notification',
        'data' => ['title' => 'Private notification'],
    ]));

    $this->actingAs($customer, 'customer')->actingAs($admin, 'web');

    $component = Livewire::test(NotificationsBell::class)
        ->call('markAsRead', $notifications[1]->id)
        ->call('markAsRead', $notifications[2]->id);

    expect($notifications[1]->fresh()->read_at)->toBeNull()
        ->and($notifications[2]->fresh()->read_at)->toBeNull();

    $component->call('markAsRead', $notifications[0]->id)->assertSee('aria-label="Notifications"', false);
    expect($notifications[0]->fresh()->read_at)->not->toBeNull();

    $notifications[0]->update(['read_at' => null]);
    $component->call('markAllAsRead');

    expect($notifications[0]->fresh()->read_at)->not->toBeNull()
        ->and($notifications[1]->fresh()->read_at)->toBeNull()
        ->and($notifications[2]->fresh()->read_at)->toBeNull();
});
