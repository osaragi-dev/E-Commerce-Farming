<?php

use AchyutN\FilamentLogViewer\Contracts\CanDeleteLogs;
use AchyutN\FilamentLogViewer\Contracts\LogProvider;
use AchyutN\FilamentLogViewer\LogTable;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $provider = Mockery::mock(LogProvider::class, CanDeleteLogs::class);
    $provider->shouldReceive('getRows', 'getFilesForFilter')->andReturn([]);
    $provider->shouldReceive('getFiles')->andReturn(['viewer-test.log']);
    $provider->shouldReceive('getCount')->andReturn(0);
    $provider->shouldNotReceive('deleteAll', 'deleteFile');
    $this->instance(LogProvider::class, $provider);
});

test('log viewer redirects guests and denies users without its permission', function (): void {
    $this->get(LogTable::getUrl())->assertRedirect(route('filament.admin.auth.login'));
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('operator', 'web'));
    $this->actingAs($user);
    $this->get(LogTable::getUrl())->assertForbidden();
    expect(LogTable::canAccess())->toBeFalse();
});

test('log viewer permission grants access without log clearing actions', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('operator', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('View:LogTable', 'web'));
    $this->actingAs($user);
    $this->get(LogTable::getUrl())->assertOk();
    Livewire::test(LogTable::class)->assertOk()->assertActionHidden('clear');
});

test('super admins see Reports after Sales Reports and Log Viewer in Operations', function (): void {
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($user);
    $this->get(LogTable::getUrl())->assertOk();
    $operations = collect(Filament::getNavigation())
        ->first(fn ($group): bool => $group->getLabel() === 'Operations');
    $labels = collect($operations->getItems())->map(fn ($item): string => $item->getLabel())->values();
    expect($labels->take(-3)->values()->all())->toBe(['Sales Reports', 'Log Viewer', 'Reports']);
});

test('role seeding exposes log viewer permission without granting existing roles', function (): void {
    $role = Role::findOrCreate('operator', 'web');
    app(RoleSeeder::class)->run();
    expect(Permission::query()->where('name', 'View:LogTable')->exists())->toBeTrue();
    expect($role->hasPermissionTo('View:LogTable'))->toBeFalse();
});
