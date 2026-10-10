<?php

use App\Enums\ApprovalModule;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('approval settings are available for the supported modules', function (): void {
    $settings = ApprovalSetting::query()->orderBy('module_code')->get();

    expect($settings->pluck('module_code')->all())->toBe([
        ApprovalModule::DirectOrder->value,
        ApprovalModule::PurchaseOrder->value,
        ApprovalModule::PurchaseOrderClose->value,
        ApprovalModule::PurchaseRequest->value,
        ApprovalModule::StockAdjustment->value,
        ApprovalModule::StockMovement->value,
        ApprovalModule::StockOpname->value,
        ApprovalModule::Vendor->value,
        ApprovalModule::VoidPayment->value,
    ]);
});

test('approval levels are assigned to roles in sequence', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockMovement->value)
        ->firstOrFail();
    $role = Role::query()->create([
        'name' => 'Warehouse Manager',
        'code' => 'warehouse-manager',
        'guard_name' => 'web',
    ]);
    $secondRole = Role::query()->create([
        'name' => 'Inventory Manager',
        'code' => 'inventory-manager',
        'guard_name' => 'web',
    ]);

    $first = ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
    ]);
    $second = ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $secondRole->id,
    ]);

    expect($first->level)->toBe(1)
        ->and($second->level)->toBe(2)
        ->and($first->role_id)->not->toBe($second->role_id)
        ->and($setting->levels)->toHaveCount(2);
});

test('approval levels cannot assign the same role more than once per setting', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockMovement->value)
        ->firstOrFail();
    $role = Role::query()->create([
        'name' => 'Warehouse Manager',
        'code' => 'warehouse-manager',
        'guard_name' => 'web',
    ]);

    ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
    ]);

    expect(fn (): ApprovalSettingLevel => ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
    ]))->toThrow(ValidationException::class);
});

test('approval settings allow a maximum of three levels', function (): void {
    $setting = ApprovalSetting::query()
        ->where('module_code', ApprovalModule::StockMovement->value)
        ->firstOrFail();
    $roles = collect(range(1, 3))->map(fn (int $index): Role => Role::query()->create([
        'name' => "Warehouse Manager {$index}",
        'code' => "warehouse-manager-{$index}",
        'guard_name' => 'web',
    ]));

    $roles->each(fn (Role $role): ApprovalSettingLevel => ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $role->id,
    ]));

    expect(fn (): ApprovalSettingLevel => ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $roles->first()->id,
    ]))->toThrow(ValidationException::class);

    expect(fn (): ApprovalSettingLevel => ApprovalSettingLevel::factory()->create([
        'approval_setting_id' => $setting->id,
        'role_id' => $roles->first()->id,
        'level' => 4,
    ]))->toThrow(ValidationException::class);
});
