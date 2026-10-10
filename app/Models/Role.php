<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    public const string DEFAULT_GUARD_NAME = 'web';

    protected $fillable = [
        'code',
        'sso_id',
        'name',
        'guard_name',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['sso_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            $role->code ??= $role->name;
            $role->created_by ??= self::currentAuditActorId();
            $role->updated_by = null;
        });

        static::updating(function (Role $role): bool {
            $actorId = self::currentAuditActorId();

            if ($actorId === null) {
                return false;
            }

            $role->updated_by = $actorId;

            return true;
        });
    }

    public function updateTimestamps(): static
    {
        parent::updateTimestamps();
        if (! $this->exists) {
            $this->setUpdatedAt(null);
        }

        return $this;
    }

    private static function currentAuditActorId(): ?string
    {
        $actor = auth('web')->user();

        if (
            ! $actor instanceof User
            || ! $actor->exists
            || $actor->trashed()
            || ! User::query()->whereKey($actor->getAuthIdentifier())->exists()
        ) {
            return null;
        }

        return (string) $actor->getAuthIdentifier();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
