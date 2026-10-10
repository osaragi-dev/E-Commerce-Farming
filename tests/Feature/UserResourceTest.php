<?php

use App\Enums\RecordStatus;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actor = User::factory()->create(['sso_id' => 999]);
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
});

test('users table labels the active column as active', function (): void {
    $table = Livewire::test(ListUsers::class)
        ->assertOk()
        ->instance()
        ->getTable();

    expect($table->getColumn('is_active')->getLabel())->toBe('Active');
});

test('users table does not expose lifecycle status', function (): void {
    $table = Livewire::test(ListUsers::class)
        ->assertOk()
        ->instance()
        ->getTable();

    expect($table->getColumn('record_status'))->toBeNull()
        ->and($table->getFilter('record_status'))->toBeNull();
});

test('users table places deleted at immediately after updated by', function (): void {
    $table = Livewire::test(ListUsers::class)
        ->assertOk()
        ->instance()
        ->getTable();

    $auditColumns = [
        'updatedBy.name',
        'deleted_at',
        'deletedBy.name',
    ];

    expect(array_values(array_intersect(array_keys($table->getColumns()), $auditColumns)))
        ->toBe($auditColumns)
        ->and($table->getColumn('deleted_at')->isSortable())->toBeTrue()
        ->and($table->getColumn('deleted_at')->isToggledHiddenByDefault())->toBeTrue();
});

test('users table supports role and audit sorting and filters', function (): void {
    $table = Livewire::test(ListUsers::class)
        ->assertOk()
        ->instance()
        ->getTable();

    expect($table->getColumn('roles.name')->isSortable())->toBeTrue()
        ->and($table->getColumn('createdBy.name')->isSortable())->toBeTrue()
        ->and($table->getColumn('deletedBy.name')->isSortable())->toBeTrue()
        ->and($table->getColumn('deleted_at')->isSortable())->toBeTrue()
        ->and($table->getFilter('roles'))->not->toBeNull()
        ->and($table->getFilter('created_by'))->not->toBeNull()
        ->and($table->getFilter('deleted_by'))->not->toBeNull()
        ->and($table->getFilter('deleted_at'))->not->toBeNull();
});

test('users can be sorted and filtered by internal role', function (): void {
    $adminRole = Role::findOrCreate('admin', 'web');
    $staffRole = Role::findOrCreate('staff', 'web');
    $admin = User::factory()->create(['email' => 'role-admin@example.test']);
    $staff = User::factory()->create(['email' => 'role-staff@example.test']);

    $admin->assignRole($adminRole);
    $staff->assignRole($staffRole);

    Livewire::test(ListUsers::class)
        ->sortTable('roles.name', 'asc')
        ->assertCanSeeTableRecords([$admin, $staff], inOrder: true);

    Livewire::test(ListUsers::class)
        ->filterTable('roles', $staffRole)
        ->assertCanSeeTableRecords([$staff])
        ->assertCanNotSeeTableRecords([$admin]);
});

test('users sort new and edited records by latest activity', function (): void {
    $this->freezeTime();
    $old = User::factory()->create(['email' => 'old-user@example.test']);
    $this->travel(1)->minute();
    $new = User::factory()->create(['email' => 'new-user@example.test']);

    Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$new, $old], inOrder: true);

    $this->travel(1)->minute();
    $old->update(['name' => 'Recently edited']);

    Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$old, $new], inOrder: true);
});

test('users table sorts audit actors by name', function (): void {
    $createdByAlpha = User::factory()->create([
        'name' => 'Alice Creator',
        'email' => 'alice-creator@example.test',
    ]);
    $createdByZulu = User::factory()->create([
        'name' => 'Zoe Creator',
        'email' => 'zoe-creator@example.test',
    ]);
    $createdByAlphaUser = User::factory()->create([
        'name' => 'Created By Alpha User',
        'email' => 'created-by-alpha@example.test',
    ]);
    $createdByZuluUser = User::factory()->create([
        'name' => 'Created By Zulu User',
        'email' => 'created-by-zulu@example.test',
    ]);
    $createdByAlphaUser->forceFill(['created_by' => $createdByAlpha->id])->saveQuietly();
    $createdByZuluUser->forceFill(['created_by' => $createdByZulu->id])->saveQuietly();

    $component = Livewire::test(ListUsers::class)
        ->sortTable('createdBy.name', 'asc');
    $ascendingIds = $component->instance()->getTableRecords()->pluck('id')->all();

    $component->sortTable('createdBy.name', 'desc');
    $descendingIds = $component->instance()->getTableRecords()->pluck('id')->all();

    expect(array_search($createdByAlphaUser->id, $ascendingIds, true))
        ->toBeLessThan(array_search($createdByZuluUser->id, $ascendingIds, true))
        ->and(array_search($createdByZuluUser->id, $descendingIds, true))
        ->toBeLessThan(array_search($createdByAlphaUser->id, $descendingIds, true));
});

test('users table sorts updated actors by name and handles missing actors', function (): void {
    $updatedByAlpha = User::factory()->create([
        'name' => 'Alice Updater',
        'email' => 'alice-updater@example.test',
    ]);
    $updatedByZulu = User::factory()->create([
        'name' => 'Zoe Updater',
        'email' => 'zoe-updater@example.test',
    ]);
    $updatedByAlphaUser = User::factory()->create([
        'name' => 'Updated By Alpha User',
        'email' => 'updated-by-alpha@example.test',
    ]);
    $updatedByZuluUser = User::factory()->create([
        'name' => 'Updated By Zulu User',
        'email' => 'updated-by-zulu@example.test',
    ]);
    $missingActorUser = User::factory()->create([
        'name' => 'Missing Updated Actor',
        'email' => 'missing-updated-actor@example.test',
    ]);
    $updatedByAlphaUser->forceFill(['updated_by' => $updatedByAlpha->id])->saveQuietly();
    $updatedByZuluUser->forceFill(['updated_by' => $updatedByZulu->id])->saveQuietly();
    $missingActorUser->forceFill(['updated_by' => null])->saveQuietly();

    $component = Livewire::test(ListUsers::class)
        ->sortTable('updatedBy.name', 'asc');
    $ascendingIds = $component->instance()->getTableRecords()->pluck('id')->all();

    expect(array_search($updatedByAlphaUser->id, $ascendingIds, true))
        ->toBeLessThan(array_search($updatedByZuluUser->id, $ascendingIds, true))
        ->and($ascendingIds)->toContain($missingActorUser->id);
});

test('users table exposes local account management actions', function (): void {
    Livewire::test(ListUsers::class)
        ->assertTableActionExists('assignRole')
        ->assertTableActionExists('edit')
        ->assertTableActionExists('resetPassword')
        ->assertTableActionDoesNotExist('toggleActive');
});

test('users list offers account creation without default credentials', function (): void {
    Livewire::test(ListUsers::class)
        ->assertSee('Add User')
        ->assertDontSee('Password Default: 12345678');
});

test('local user creation URL is unavailable', function (): void {
    $this->get('/admin/users/create')->assertNotFound();
});

test('local user edit URL cannot change the saved identity', function (): void {
    $user = User::factory()->create();
    $this->get('/admin/users/'.$user->id.'/edit')->assertNotFound();
    expect($user->refresh()->record_status)->toBe(RecordStatus::Saved);
});

test('super administrators can create local accounts through modals', function (): void {
    expect(UserResource::canCreate())->toBeTrue();
    expect(array_keys(UserResource::getPages()))->toBe(['index']);
});

test('user model rejects draft status on create and update', function (): void {
    expect(fn (): User => User::factory()->create(['record_status' => RecordStatus::Draft]))
        ->toThrow(ValidationException::class);

    $user = User::factory()->create();

    expect(fn (): bool => $user->forceFill(['record_status' => RecordStatus::Draft])->saveQuietly())
        ->toThrow(ValidationException::class);

    expect($user->fresh()->record_status)->toBe(RecordStatus::Saved);
});

test('new user model records retain creation audit metadata', function (): void {
    $user = User::factory()->create();
    expect($user->created_by)->toBe($this->actor->id)
        ->and($user->created_at)->not->toBeNull()
        ->and($user->updated_by)->toBeNull()
        ->and($user->updated_at)->toBeNull();
});

test('active local and formerly linked users with an internal role can access the panel', function (): void {
    $inactive = User::factory()->create(['sso_id' => 101, 'is_active' => false]);
    $saved = User::factory()->create(['sso_id' => 102]);
    $local = User::factory()->create();
    $role = Role::findOrCreate('staff', 'web');
    $inactive->assignRole($role);
    $saved->assignRole($role);
    $local->assignRole($role);
    expect($inactive->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($local->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($saved->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

test('users table resets a password and requires a change at next login', function (): void {
    $user = User::factory()->create(['password' => 'old-password']);
    $hash = $user->password;
    Livewire::test(ListUsers::class)->callTableAction('resetPassword', $user, data: [
        'password' => 'temporary-secret', 'password_confirmation' => 'temporary-secret',
    ])->assertHasNoActionErrors();
    expect(Hash::check('temporary-secret', $user->refresh()->password))->toBeTrue()
        ->and($user->password)->not->toBe($hash)
        ->and($user->must_change_password)->toBeTrue();
});

test('user resource permits identity edits for a super administrator', function (): void {
    $user = User::factory()->create();
    expect(UserResource::canEdit($user))->toBeTrue();
});

test('users table cannot delete or restore centrally owned accounts', function (): void {
    Livewire::test(ListUsers::class)
        ->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('restore');
});

test('user export excludes lifecycle status and credentials', function (): void {
    expect(UserResource::exportDefinition()->columns)
        ->toHaveKey('is_active', 'Active')
        ->not->toHaveKey('record_status')
        ->not->toHaveKey('password');
});

test('users table excludes customer accounts', function (): void {
    $customer = User::factory()->create(['email' => 'customer@example.test']);
    $customer->assignRole(Role::findOrCreate('customer', 'web'));
    $staff = User::factory()->create(['email' => 'staff@example.test']);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$staff])
        ->assertCanNotSeeTableRecords([$customer]);
});

test('role assignment rejects a customer role submitted directly', function (): void {
    Http::preventStrayRequests();
    $customerRole = Role::findOrCreate('customer', 'web');
    $user = User::factory()->create(['sso_id' => 103]);
    Livewire::test(ListUsers::class)
        ->callTableAction('assignRole', $user, data: ['roles' => [$customerRole->id]])
        ->assertHasTableActionErrors(['roles.0']);
    expect($user->refresh()->roles)->toBeEmpty();
    Http::assertNothingSent();
});

test('administrators create local accounts with temporary passwords in a modal', function (): void {
    Http::preventStrayRequests();
    Livewire::test(ListUsers::class)->callAction('create', data: [
        'name' => 'Local Staff', 'email' => 'local-staff@example.test',
        'password' => 'temporary-staff-password', 'is_active' => true,
    ])->assertHasNoActionErrors();
    $user = User::where('email', 'local-staff@example.test')->sole();
    expect(Hash::check('temporary-staff-password', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->record_status)->toBe(RecordStatus::Saved)
        ->and($user->created_by)->toBe($this->actor->id);
    Http::assertNothingSent();
});

test('editing a local identity preserves its password and role memberships', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::factory()->create());
    $hash = $user->password;
    $roles = $user->getRoleNames()->all();
    Livewire::test(ListUsers::class)->callTableAction('edit', $user, data: [
        'name' => 'Updated Staff', 'email' => $user->email, 'is_active' => false,
    ])->assertHasNoActionErrors();
    expect($user->refresh()->name)->toBe('Updated Staff')->and($user->is_active)->toBeFalse()
        ->and($user->password)->toBe($hash)->and($user->getRoleNames()->all())->toBe($roles)
        ->and($user->updated_by)->toBe($this->actor->id);
});

test('users without management permissions cannot create edit or reset accounts', function (): void {
    $viewer = User::factory()->create();
    $role = Role::factory()->create();
    $role->givePermissionTo(Permission::findOrCreate('ViewAny:User', 'web'));
    $viewer->assignRole($role);
    $this->actingAs($viewer);
    $user = User::factory()->create();
    Livewire::test(ListUsers::class)->assertActionHidden('create')
        ->assertTableActionHidden('edit', $user)
        ->assertTableActionHidden('resetPassword', $user)
        ->assertTableActionHidden('assignRole', $user);
});

test('temporary password reset rejects mismatched confirmation without changing credentials', function (): void {
    $user = User::factory()->create();
    $hash = $user->password;
    Livewire::test(ListUsers::class)->callTableAction('resetPassword', $user, data: [
        'password' => 'temporary-secret', 'password_confirmation' => 'different-secret',
    ])->assertHasActionErrors(['password']);
    expect($user->refresh()->password)->toBe($hash);
});
