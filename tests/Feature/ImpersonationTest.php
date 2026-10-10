<?php

use App\Filament\Pages\ChangePassword;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

function impersonationUser(array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $role = Role::factory()->create();
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user->assignRole($role);

    return $user;
}

test('an authorized administrator can impersonate and return without changing credentials', function (): void {
    Http::preventStrayRequests();
    Log::spy();
    $actor = impersonationUser(['ViewAny:User', 'Impersonate:User']);
    $target = impersonationUser();
    $password = $target->password;
    $this->actingAs($actor, 'web');

    Livewire::test(ListUsers::class)->callTableAction('impersonate', $target)
        ->assertRedirect(Filament::getPanel('admin')->getUrl());

    expect(auth('web')->id())->toBe($target->id)
        ->and(session('impersonated_by'))->toBe($actor->id);
    $this->get('/admin')->assertOk()->assertSee('impersonate-banner');
    $this->get(route('filament-impersonate.leave'))->assertRedirect(UserResource::getUrl());
    expect(auth('web')->id())->toBe($actor->id)
        ->and(session()->has('impersonated_by'))->toBeFalse()
        ->and($target->fresh()->password)->toBe($password);
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->with('impersonation_entered', [
        'actor_id' => $actor->id, 'target_user_id' => $target->id, 'guard' => 'web',
    ])->once();
    Log::shouldHaveReceived('info')->with('impersonation_left', [
        'actor_id' => $actor->id, 'target_user_id' => $target->id, 'guard' => 'web',
    ])->once();
});

test('impersonation refuses an actor without permission', function (): void {
    $actor = impersonationUser(['ViewAny:User']);
    $target = impersonationUser();
    $this->actingAs($actor);

    Livewire::test(ListUsers::class)->assertTableActionHidden('impersonate', $target)
        ->mountAction(TestAction::make('impersonate')->table($target))->callMountedAction();

    expect(auth('web')->id())->toBe($actor->id)
        ->and(session()->has('impersonated_by'))->toBeFalse();
});

test('impersonation refuses unavailable targets', function (string $state): void {
    $actor = impersonationUser(['ViewAny:User', 'Impersonate:User']);
    $target = impersonationUser();
    match ($state) {
        'self' => $target = $actor,
        'inactive' => $target->update(['is_active' => false]),
        'customer' => $target->assignRole(Role::findOrCreate('customer', 'web')),
        'deleted' => $target->delete(),
        'no role' => $target->syncRoles([]),
    };
    $this->actingAs($actor);

    Livewire::test(ListUsers::class)->mountAction(TestAction::make('impersonate')->table($target))->callMountedAction();
    expect(auth('web')->id())->toBe($actor->id)
        ->and(session()->has('impersonated_by'))->toBeFalse();
})->with(['self', 'inactive', 'customer', 'deleted', 'no role']);

test('impersonation cannot be nested', function (): void {
    $actor = impersonationUser(['ViewAny:User', 'Impersonate:User']);
    $target = impersonationUser(['ViewAny:User', 'Impersonate:User']);
    $other = impersonationUser();
    $this->actingAs($actor);
    Livewire::test(ListUsers::class)->callTableAction('impersonate', $target);
    Livewire::test(ListUsers::class)->assertTableActionHidden('impersonate', $other)
        ->mountAction(TestAction::make('impersonate')->table($other))->callMountedAction();
    expect(auth('web')->id())->toBe($target->id)
        ->and(session('impersonated_by'))->toBe($actor->id);
});

test('impersonation preserves mandatory password change and blocks password updates', function (): void {
    Http::preventStrayRequests();
    $actor = impersonationUser(['ViewAny:User', 'Impersonate:User']);
    $target = impersonationUser([], ['must_change_password' => true]);
    $this->actingAs($target);
    $passwordPage = Livewire::test(ChangePassword::class);
    $this->actingAs($actor);
    Livewire::test(ListUsers::class)->callTableAction('impersonate', $target);
    $this->get('/admin')->assertOk();
    $this->get(ChangePassword::getUrl())->assertRedirect(Filament::getPanel('admin')->getUrl());
    $passwordPage->call('save')->assertRedirect(Filament::getPanel('admin')->getUrl());
    expect($target->fresh()->must_change_password)->toBeTrue();
    Http::assertNothingSent();
    $this->get(route('filament-impersonate.leave'));
    $this->actingAs($target);
    $this->get('/admin')->assertRedirect(ChangePassword::getUrl());
});

test('impersonation permission backfills only user management roles', function (): void {
    $manager = Role::factory()->create();
    $viewer = Role::factory()->create();
    $manager->givePermissionTo(Permission::findOrCreate('Update:User', 'web'));
    $viewer->givePermissionTo(Permission::findOrCreate('ViewAny:User', 'web'));

    (require database_path('migrations/2026_10_01_102500_add_user_impersonation_permission.php'))->up();

    expect($manager->fresh()->hasPermissionTo('Impersonate:User'))->toBeTrue()
        ->and($viewer->fresh()->hasPermissionTo('Impersonate:User'))->toBeFalse();
});

test('an actor must finish their own password change before impersonating', function (): void {
    $actor = impersonationUser(['ViewAny:User', 'Impersonate:User'], ['must_change_password' => true]);
    $target = impersonationUser();
    $this->actingAs($actor);
    Livewire::test(ListUsers::class)->mountAction(TestAction::make('impersonate')->table($target))->callMountedAction();
    expect(auth('web')->id())->toBe($actor->id)
        ->and(session()->has('impersonated_by'))->toBeFalse();
});

test('legacy customer accounts cannot be impersonated from users', function (): void {
    $actor = impersonationUser(['ViewAny:User', 'Impersonate:User']);
    $target = impersonationUser();
    $target->assignRole(Role::findOrCreate('customer', 'web'));
    $this->actingAs($actor);
    Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$target]);
    expect($target->canBeImpersonated())->toBeFalse();
});

test('customer sessions remain independent of back office impersonation', function (): void {
    $actor = impersonationUser(['ViewAny:User', 'Impersonate:User']);
    $target = impersonationUser();
    $customer = Customer::factory()->create();
    auth('customer')->login($customer);
    $this->actingAs($actor, 'web');
    Livewire::test(ListUsers::class)->callTableAction('impersonate', $target);
    expect(auth('customer')->id())->toBe($customer->id);
    auth('customer')->logout();
    expect(session('impersonated_by'))->toBe($actor->id);
    $this->get(route('filament-impersonate.leave'));
    expect(auth('web')->id())->toBe($actor->id);
});
