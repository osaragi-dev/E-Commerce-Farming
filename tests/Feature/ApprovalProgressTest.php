<?php

use App\Enums\ApprovalModule;
use App\Enums\StockOpnameStatus;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Resources\StockAdjustments\Support\StockAdjustmentDetailPresenter;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Filament\Resources\StockMovements\Support\StockMovementDetailPresenter;
use App\Filament\Resources\StockOpnames\Pages\ViewStockOpname;
use App\Filament\Resources\StockOpnames\Support\StockOpnameDetailPresenter;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Services\ApprovalWorkflow;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

test('inventory approval progress matches the configured level count', function (ApprovalModule $module, int $levelCount): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $actor = User::factory()->create();
    $actor->assignRole($role);
    $this->actingAs($actor);

    $setting = ApprovalSetting::query()->where('module_code', $module->value)->firstOrFail();
    $setting->update(['is_active' => true]);

    for ($level = 1; $level <= $levelCount; $level++) {
        ApprovalSettingLevel::factory()->create([
            'approval_setting_id' => $setting->id,
            'role_id' => $role->id,
            'level' => $level,
        ]);
    }

    [$record, $presenter, $viewPage, $beforeApproval, $afterApproval] = match ($module) {
        ApprovalModule::StockAdjustment => [
            StockAdjustment::factory()->create(['approval_setting_id' => $setting->id]),
            app(StockAdjustmentDetailPresenter::class),
            ViewStockAdjustment::class,
            ['Requested'],
            ['Posted'],
        ],
        ApprovalModule::StockMovement => [
            StockMovement::factory()->create(['approval_setting_id' => $setting->id]),
            app(StockMovementDetailPresenter::class),
            ViewStockMovement::class,
            ['Requested'],
            ['Shipped', 'Received'],
        ],
        ApprovalModule::StockOpname => [
            StockOpnameSession::factory()->create([
                'approval_setting_id' => $setting->id,
                'status' => StockOpnameStatus::Counting,
            ]),
            app(StockOpnameDetailPresenter::class),
            ViewStockOpname::class,
            ['Requested', 'Started'],
            ['Ended', 'Finalized'],
        ],
    };

    $expectedLabels = [
        ...$beforeApproval,
        ...array_map(fn (int $level): string => "Level {$level}", range(1, $levelCount - 1)),
        'Approved',
        ...$afterApproval,
    ];

    expect(collect($presenter->timeline($record))->pluck('label')->all())->toBe($expectedLabels);

    for ($level = 1; $level <= $levelCount; $level++) {
        Livewire::test($viewPage, ['record' => $record->getRouteKey()])
            ->assertActionHasLabel('approve', "Approve Level {$level}");

        app(ApprovalWorkflow::class)->approve($record, $actor);
        $record->refresh();
        $steps = collect($presenter->timeline($record));

        if ($level < $levelCount) {
            expect($record->approval_status->value)->toBe('pending')
                ->and($record->approval_level)->toBe($level + 1)
                ->and($steps->firstWhere('key', "level-{$level}")['state'])->toBe('complete')
                ->and($steps->firstWhere('key', 'approved')['state'])->toBe($level === $levelCount - 1 ? 'current' : 'pending');

            Livewire::test($viewPage, ['record' => $record->getRouteKey()])
                ->assertSee("L{$level} Approved");
        } else {
            expect($record->approval_status->value)->toBe('approved')
                ->and($steps->firstWhere('key', 'approved')['state'])->toBe('complete');

            Livewire::test($viewPage, ['record' => $record->getRouteKey()])
                ->assertActionHidden('approve');
        }
    }
})->with([
    'adjustment with two levels' => [ApprovalModule::StockAdjustment, 2],
    'adjustment with three levels' => [ApprovalModule::StockAdjustment, 3],
    'movement with two levels' => [ApprovalModule::StockMovement, 2],
    'movement with three levels' => [ApprovalModule::StockMovement, 3],
    'opname with two levels' => [ApprovalModule::StockOpname, 2],
    'opname with three levels' => [ApprovalModule::StockOpname, 3],
]);
