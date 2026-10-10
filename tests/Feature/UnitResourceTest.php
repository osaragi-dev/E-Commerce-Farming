<?php

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Filament\Resources\Units\UnitResource;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
});

test('units are visible under the master data navigation', function (): void {
    expect(UnitResource::shouldRegisterNavigation())->toBeTrue()
        ->and(UnitResource::getNavigationGroup())->toBe('Master Data')
        ->and(UnitResource::getNavigationSort())->toBeLessThan(ProductResource::getNavigationSort())
        ->and(UnitResource::getNavigationSort())->toBeLessThan(CustomerResource::getNavigationSort());
});

test('unit code can be set from the unit form', function (): void {
    Livewire::test(ManageUnits::class)
        ->callAction('create', data: [
            'code' => 'kg',
            'name' => 'Kilogram',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $unit = Unit::query()->where('name', 'Kilogram')->firstOrFail();

    expect($unit->code)->toBe('kg');
});

test('unit code is generated from the unit name when omitted', function (): void {
    $unit = Unit::query()->create(['name' => 'Kilogram']);

    expect($unit->code)->toBe('kilogram');
});

test('unit code and active status are exposed in admin display definitions', function (): void {
    expect(UnitResource::exportDefinition()->columns)
        ->toHaveKeys(['code', 'is_active'])
        ->and(Unit::factory()->create()->is_active)->toBeTrue();
});

test('unit table displays code and active status', function (): void {
    Livewire::test(ManageUnits::class)
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('is_active');
});

test('duplicate saved unit names show a save error notification', function (): void {
    Unit::factory()->create([
        'name' => 'Kilogram',
        'code' => 'kilogram',
    ]);

    Livewire::test(ManageUnits::class)
        ->callAction('create', data: [
            'code' => 'kilogram',
            'name' => 'Kilogram',
            'is_active' => true,
        ])
        ->assertNotified('Gagal disimpan')
        ->assertHasActionErrors(['code']);
});
