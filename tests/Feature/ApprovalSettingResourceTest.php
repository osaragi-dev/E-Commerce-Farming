<?php

use App\Filament\Resources\ApprovalSettings\ApprovalSettingResource;
use App\Filament\Resources\ApprovalSettings\Pages\ManageApprovalSettings;
use App\Models\ApprovalSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->create();
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
});

test('approval settings table exposes the supported modules and edit action', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', 'stock_movement')->firstOrFail();

    Livewire::test(ManageApprovalSettings::class)
        ->assertTableColumnExists('module_code')
        ->assertTableColumnExists('levels_count')
        ->assertTableColumnExists('is_active')
        ->assertTableActionExists('edit', record: $setting);

    expect(ApprovalSettingResource::getNavigationLabel())->toBe('Setting Approval');
});

test('approval settings table displays an unknown legacy module without failing', function (): void {
    $setting = ApprovalSetting::factory()->create(['module_code' => 'vendor']);

    Livewire::test(ManageApprovalSettings::class)
        ->assertCanSeeTableRecords([$setting])
        ->assertSee('Vendor');
});

test('approval setting form limits approval levels to three', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', 'stock_movement')->firstOrFail();

    $component = Livewire::test(ManageApprovalSettings::class)
        ->mountTableAction('edit', $setting);

    expect($component->instance()->getMountedTableActionForm()?->getComponent('levels')?->getMaxItems())
        ->toBe(3);
});
