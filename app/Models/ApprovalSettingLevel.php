<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['approval_setting_id', 'role_id', 'level'])]
class ApprovalSettingLevel extends Model
{
    use HasFactory, HasUuidPrimaryKey;

    public const MAX_LEVELS = 3;

    protected static function booted(): void
    {
        static::creating(function (self $level): void {
            $level->level ??= ((int) self::query()
                ->where('approval_setting_id', $level->approval_setting_id)
                ->max('level')) + 1;

            $level->ensureWithinMaximum();
        });

        static::updating(function (self $level): void {
            $level->ensureWithinMaximum();
        });
    }

    private function ensureWithinMaximum(): void
    {
        $hasReachedMaximum = ! $this->exists
            && self::query()
                ->where('approval_setting_id', $this->approval_setting_id)
                ->count() >= self::MAX_LEVELS;

        if ($hasReachedMaximum || (int) $this->level > self::MAX_LEVELS) {
            throw ValidationException::withMessages([
                'levels' => 'An approval setting can have a maximum of 3 approval levels.',
            ]);
        }

        $duplicateRole = self::query()
            ->where('approval_setting_id', $this->approval_setting_id)
            ->where('role_id', $this->role_id)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists();

        if ($duplicateRole) {
            throw ValidationException::withMessages([
                'role_id' => 'Each approval level must use a different role.',
            ]);
        }
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
