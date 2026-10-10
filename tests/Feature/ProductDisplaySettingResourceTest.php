<?php

use App\Filament\Resources\ProductDisplaySetting\Pages\EditProductDisplaySetting;
use App\Filament\Resources\ProductDisplaySetting\ProductDisplaySettingResource;
use App\Models\ProductDisplaySetting;
use App\Models\Role;
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

test('product display settings are grouped under master data', function (): void {
    expect(ProductDisplaySettingResource::getNavigationGroup())->toBe('Master Data');
});

test('product display settings show last update audit fields', function (): void {
    $livewire = Livewire::test(EditProductDisplaySetting::class, [
        'record' => ProductDisplaySetting::current()->getRouteKey(),
    ])
        ->assertFormComponentExists('last_updated_at')
        ->assertFormComponentExists('last_updated_by');
    $schema = $livewire->instance()->getSchema('form');
    $audit = collect($schema->getComponents())
        ->first(fn ($component): bool => $component->getHeading() === 'Audit');

    expect($audit->isCollapsed())->toBeFalse()
        ->and($audit->isCollapsible())->toBeTrue();
});
