<?php

use App\Enums\ApprovalModule;
use App\Enums\RecordStatus;
use App\Models\ApprovalSetting;
use App\Models\ApprovalSettingLevel;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('vendors use the baseline lifecycle', function (): void {
    $record = Vendor::factory()->create();

    expect($record->id)->toBeString()
        ->and($record->record_status)->toBe(RecordStatus::Saved)
        ->and($record->markAsDraft())->toBeTrue()
        ->and($record->fresh()->record_status)->toBe(RecordStatus::Draft);
});

test('saved vendors capture the vendor approval setting and create a request log', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', ApprovalModule::Vendor->value)->firstOrFail();
    $setting->update(['is_active' => true]);
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);

    $vendor = Vendor::factory()->create();

    expect($vendor->approval_status->value)->toBe('requested')
        ->and($vendor->approval_level)->toBe(1)
        ->and($vendor->approval_setting_id)->toBe($setting->id)
        ->and($vendor->approvalLogs()->where('action', 'requested')->exists())->toBeTrue();
});

test('draft vendors do not start approval until they are saved', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', ApprovalModule::Vendor->value)->firstOrFail();
    $setting->update(['is_active' => true]);
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);

    $vendor = Vendor::factory()->create(['record_status' => RecordStatus::Draft]);

    expect($vendor->approval_status->value)->toBe('pending')
        ->and($vendor->approval_level)->toBe(0)
        ->and($vendor->approvalLogs()->exists())->toBeFalse();

    $vendor->markAsSaved();

    expect($vendor->fresh()->approval_status->value)->toBe('requested')
        ->and($vendor->fresh()->approval_level)->toBe(1)
        ->and($vendor->approvalLogs()->where('action', 'requested')->exists())->toBeTrue();
});

test('editing a revised vendor starts approval again', function (): void {
    $setting = ApprovalSetting::query()->where('module_code', ApprovalModule::Vendor->value)->firstOrFail();
    $setting->update(['is_active' => true]);
    ApprovalSettingLevel::factory()->create(['approval_setting_id' => $setting->id]);

    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['approval_status' => 'revised'])->saveQuietly();
    $vendor->name = 'Updated vendor name';
    $vendor->save();

    expect($vendor->fresh()->approval_status->value)->toBe('requested')
        ->and($vendor->fresh()->approval_level)->toBe(1)
        ->and($vendor->approvalLogs()->where('action', 'requested')->count())->toBe(2);
});
