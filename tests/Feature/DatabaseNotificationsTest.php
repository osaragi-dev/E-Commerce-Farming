<?php

use App\Filament\Livewire\PermissionFilteredDatabaseNotifications;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\NewOrderForAdmins;
use App\Notifications\StockLevelAlertForAdmins;
use App\Notifications\StockMovementRecommendationForAdmins;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Filament::setCurrentPanel('admin');
});

function createFilamentPermissionNotification(User $user, string $type, string $title): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid7(),
        'type' => $type,
        'data' => [
            'format' => 'filament',
            'title' => $title,
        ],
    ]);
}

function giveNotificationResourceAccess(User $user, string $resource, string $roleName): void
{
    $role = Role::findOrCreate($roleName, 'web');
    $role->givePermissionTo(Permission::findOrCreate("ViewAny:{$resource}", 'web'));
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

test('Filament can filter unread database notifications by JSON format', function (): void {
    $user = User::factory()->create();

    $user->notifications()->create([
        'id' => (string) Str::uuid7(),
        'type' => 'test-notification',
        'data' => [
            'format' => 'filament',
            'title' => 'Test notification',
        ],
    ]);

    $count = $user->notifications()
        ->where('data->format', 'filament')
        ->unread()
        ->count();

    expect($count)->toBe(1);
});

test('admin panel uses the permission filtered database notification component', function (): void {
    expect(Filament::getPanel('admin')->getDatabaseNotificationsLivewireComponent())
        ->toBe(PermissionFilteredDatabaseNotifications::class);
});

test('database notifications only show categories permitted to the user', function (): void {
    $user = User::factory()->create();
    giveNotificationResourceAccess($user, 'Order', 'orders_notifier');

    createFilamentPermissionNotification($user, NewOrderForAdmins::class, 'Order notification');
    createFilamentPermissionNotification($user, StockLevelAlertForAdmins::class, 'Stock notification');
    createFilamentPermissionNotification($user, 'unknown-notification', 'Unknown notification');

    $this->actingAs($user, 'web');
    $component = Livewire::test(PermissionFilteredDatabaseNotifications::class)
        ->assertSee('Order notification')
        ->assertDontSee('Stock notification')
        ->assertDontSee('Unknown notification');

    expect($component->instance()->getUnreadNotificationsCount())->toBe(1);
});

test('database notifications are hidden when the user has no matching resource permission', function (): void {
    $user = User::factory()->create();

    createFilamentPermissionNotification($user, NewOrderForAdmins::class, 'Order notification');

    $this->actingAs($user, 'web');

    $component = Livewire::test(PermissionFilteredDatabaseNotifications::class)
        ->assertDontSee('Order notification');

    expect($component->instance()->getUnreadNotificationsCount())->toBe(0);
});

test('database notifications show all categories granted by assigned roles', function (): void {
    $user = User::factory()->create();
    giveNotificationResourceAccess($user, 'Order', 'orders_and_stock_orders');
    giveNotificationResourceAccess($user, 'Stock', 'orders_and_stock_stock');

    createFilamentPermissionNotification($user, NewOrderForAdmins::class, 'Order notification');
    createFilamentPermissionNotification($user, StockLevelAlertForAdmins::class, 'Stock notification');
    createFilamentPermissionNotification($user, StockMovementRecommendationForAdmins::class, 'Stock movement notification');

    $this->actingAs($user, 'web');

    Livewire::test(PermissionFilteredDatabaseNotifications::class)
        ->assertSee('Order notification')
        ->assertSee('Stock notification')
        ->assertDontSee('Stock movement notification');
});

test('notification read and clear actions only change categories visible to the user', function (): void {
    $user = User::factory()->create();
    giveNotificationResourceAccess($user, 'Order', 'orders_action_access');

    $orderNotification = createFilamentPermissionNotification($user, NewOrderForAdmins::class, 'Order notification');
    $stockNotification = createFilamentPermissionNotification($user, StockLevelAlertForAdmins::class, 'Stock notification');
    $readStockNotification = $user->notifications()->create([
        'id' => (string) Str::uuid7(),
        'type' => StockLevelAlertForAdmins::class,
        'data' => ['format' => 'filament', 'title' => 'Read stock notification'],
        'read_at' => now(),
    ]);

    $this->actingAs($user, 'web');

    $component = Livewire::test(PermissionFilteredDatabaseNotifications::class);

    $component->call('markNotificationAsRead', $stockNotification->id);
    $component->call('markNotificationAsUnread', $readStockNotification->id);
    expect($stockNotification->fresh()->read_at)->toBeNull()
        ->and($readStockNotification->fresh()->read_at)->not->toBeNull();

    $component->call('markNotificationAsRead', $orderNotification->id);
    expect($orderNotification->fresh()->read_at)->not->toBeNull();

    $component->call('markNotificationAsUnread', $orderNotification->id);
    expect($orderNotification->fresh()->read_at)->toBeNull();

    $component->call('markAllNotificationsAsRead');
    expect($orderNotification->fresh()->read_at)->not->toBeNull()
        ->and($stockNotification->fresh()->read_at)->toBeNull();

    $component->call('removeNotification', $stockNotification->id);
    expect($stockNotification->fresh())->not->toBeNull();

    $component->call('clearNotifications');
    expect($orderNotification->fresh())->toBeNull()
        ->and($stockNotification->fresh())->not->toBeNull()
        ->and($readStockNotification->fresh())->not->toBeNull();
});
