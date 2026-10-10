<?php

use App\Enums\RecordStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

test('draft user migration converts existing accounts and restore history without changing active flags', function (): void {
    $active = User::factory()->create(['is_active' => true]);
    $inactive = User::factory()->create(['is_active' => false]);
    $deleted = User::factory()->create();
    $deleted->delete();
    $saved = User::factory()->create();

    DB::table('users')->whereIn('id', [$active->id, $inactive->id])->update(['record_status' => 'draft']);
    DB::table('users')->where('id', $deleted->id)->update(['previous_record_status' => 'draft']);

    $migration = require database_path('migrations/2026_09_25_152720_convert_draft_users_to_saved.php');
    $migration->up();
    $migration->up();

    expect($active->refresh()->record_status)->toBe(RecordStatus::Saved)
        ->and($active->is_active)->toBeTrue()
        ->and($active->sso_id)->toBeNull()
        ->and($active->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($inactive->refresh()->record_status)->toBe(RecordStatus::Saved)
        ->and($inactive->is_active)->toBeFalse()
        ->and($inactive->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and($deleted->refresh()->record_status)->toBe(RecordStatus::Deleted)
        ->and($deleted->previous_record_status)->toBe(RecordStatus::Saved)
        ->and($saved->refresh()->record_status)->toBe(RecordStatus::Saved);

    $deleted->restore();

    expect($deleted->refresh()->record_status)->toBe(RecordStatus::Saved);
});
