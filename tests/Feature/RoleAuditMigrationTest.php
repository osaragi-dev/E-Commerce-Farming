<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('role audit migration clears untraceable timestamps and is idempotent', function (): void {
    $actor = User::factory()->create();
    $legacyRoleId = (string) Str::orderedUuid();
    $auditedRoleId = (string) Str::orderedUuid();

    DB::table('roles')->insert([
        [
            'id' => $legacyRoleId,
            'code' => 'legacy_audit_role',
            'name' => 'Legacy Audit Role',
            'guard_name' => 'web',
            'created_by' => null,
            'updated_by' => null,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHour(),
            'deleted_at' => null,
        ],
        [
            'id' => $auditedRoleId,
            'code' => 'tracked_audit_role',
            'name' => 'Tracked Audit Role',
            'guard_name' => 'web',
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHour(),
            'deleted_at' => null,
        ],
    ]);

    $migration = require database_path('migrations/2026_09_22_162503_normalize_role_audit_timestamps.php');
    $trackedUpdatedAt = DB::table('roles')->where('id', $auditedRoleId)->value('updated_at');

    $migration->up();

    expect(DB::table('roles')->where('id', $legacyRoleId)->value('updated_at'))->toBeNull()
        ->and(DB::table('roles')->where('id', $auditedRoleId)->value('updated_at'))->toBe($trackedUpdatedAt);

    $migration->up();

    expect(DB::table('roles')->where('id', $legacyRoleId)->value('updated_at'))->toBeNull()
        ->and(DB::table('roles')->where('id', $auditedRoleId)->value('updated_at'))->toBe($trackedUpdatedAt);
});
