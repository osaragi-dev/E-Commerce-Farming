<?php

use App\Enums\ApprovalModule;
use App\Enums\RecordStatus;
use App\Enums\StockOpnameStatus;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Services\ApprovalWorkflow;

test('farming admin can decide every inventory approval level by role code', function (ApprovalModule $module, string $decision): void {
    $actor = User::factory()->create();
    $actor->assignRole(Role::factory()->create(['code' => 'FARMING_ADMIN', 'name' => 'Admin Farming']));
    $this->actingAs($actor);
    $setting = ApprovalSetting::query()->where('module_code', $module->value)->firstOrFail();
    ApprovalSettingLevel::factory()->count(2)->create(['approval_setting_id' => $setting->id]);
    $record = match ($module) {
        ApprovalModule::StockAdjustment => StockAdjustment::factory()->create(['record_status' => RecordStatus::Saved, 'approval_setting_id' => $setting->id]),
        ApprovalModule::StockMovement => StockMovement::factory()->create(),
        ApprovalModule::StockOpname => StockOpnameSession::factory()->create(['status' => StockOpnameStatus::Counting]),
    };
    $workflow = app(ApprovalWorkflow::class);
    $ordinaryUser = User::factory()->create();

    expect($workflow->canApprove($record, $ordinaryUser))->toBeFalse()
        ->and($workflow->canApprove($record, $actor))->toBeTrue();
    $workflow->approve($record, $actor);
    $record->refresh();
    expect($record->approval_level)->toBe(2)
        ->and($workflow->canApprove($record, $actor))->toBeTrue();

    $workflow->{$decision}($record, $actor, 'Reviewed by farming admin.');
    $record->refresh();
    expect($record->approval_status->value)->toBe(match ($decision) {
        'approve' => 'approved',
        'reject' => 'rejected',
        'revise' => 'revised',
    })->and($workflow->canApprove($record, $actor))->toBeFalse();
})->with([ApprovalModule::StockAdjustment, ApprovalModule::StockMovement, ApprovalModule::StockOpname])->with(['approve', 'reject', 'revise']);
