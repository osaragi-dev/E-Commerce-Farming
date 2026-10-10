<?php

namespace App\Models\Concerns;

use App\Enums\RecordStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait HasRecordLifecycle
{
    public function initializeHasRecordLifecycle(): void
    {
        $this->mergeFillable([
            'record_status',
            'previous_record_status',
            'created_by',
            'updated_by',
            'deleted_by',
        ]);

        $this->mergeCasts([
            'record_status' => RecordStatus::class,
            'previous_record_status' => RecordStatus::class,
        ]);
    }

    public static function bootHasRecordLifecycle(): void
    {
        static::saving(function (Model $model): void {
            $model->setAttribute('record_status', $model->getAttribute('record_status') ?? RecordStatus::Saved);

            if (
                $model->getAttribute('record_status') !== RecordStatus::Saved
                && $model->getAttribute('record_status') !== RecordStatus::Saved->value
            ) {
                return;
            }

            foreach ($model->getLifecycleUniqueAttributes() as $attribute) {
                $value = $model->getAttribute($attribute);

                if (blank($value)) {
                    continue;
                }

                $normalized = Str::lower(Str::squish((string) $value));
                $query = $model->newQuery()
                    ->whereNull('deleted_at')
                    ->where('record_status', RecordStatus::Saved)
                    ->whereRaw("lower(trim({$attribute})) = ?", [$normalized]);

                if ($model->exists) {
                    $query->whereKeyNot($model->getKey());
                }

                if ($query->exists()) {
                    throw ValidationException::withMessages([
                        $attribute => 'The '.Str::headline($attribute).' has already been used by a saved record.',
                    ]);
                }

                $model->setAttribute($attribute, Str::squish((string) $value));
            }
        });

        static::creating(function (Model $model): void {
            $model->setAttribute('record_status', $model->getAttribute('record_status') ?? RecordStatus::Saved);
            $model->setAttribute('created_by', $model->getAttribute('created_by') ?? $model->currentLifecycleActorId());
            $model->setAttribute('updated_by', null);
        });

        static::updating(function (Model $model): void {
            if (! $model->isDirty('updated_by')) {
                $model->setAttribute('updated_by', $model->currentLifecycleActorId());
            }
        });

        static::deleting(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }

            $model->setAttribute('previous_record_status', $model->getAttribute('record_status'));
            $model->setAttribute('record_status', RecordStatus::Deleted);
            $model->setAttribute('deleted_by', $model->currentLifecycleActorId());
            $model->setAttribute('updated_by', $model->currentLifecycleActorId());
            $model->saveQuietly();
        });

        static::restoring(function (Model $model): void {
            $status = $model->getAttribute('previous_record_status') ?? RecordStatus::Saved;

            if ($status === RecordStatus::Saved || $status === RecordStatus::Saved->value) {
                foreach ($model->getLifecycleUniqueAttributes() as $attribute) {
                    $value = $model->getAttribute($attribute);

                    if (blank($value)) {
                        continue;
                    }

                    $exists = $model->newQuery()
                        ->whereNull('deleted_at')
                        ->where('record_status', RecordStatus::Saved)
                        ->whereRaw("lower(trim({$attribute})) = ?", [Str::lower(Str::squish((string) $value))])
                        ->whereKeyNot($model->getKey())
                        ->exists();

                    if ($exists) {
                        $status = RecordStatus::Draft;
                        break;
                    }
                }
            }

            $model->setAttribute('record_status', $status);
            $model->setAttribute('previous_record_status', null);
            $model->setAttribute('deleted_by', null);
            $model->setAttribute('updated_by', $model->currentLifecycleActorId());
            $model->saveQuietly();
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

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        try {
            return parent::save($options);
        } catch (QueryException $exception) {
            if (! self::isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            foreach ($this->getLifecycleUniqueAttributes() as $attribute) {
                if (! Str::contains(Str::lower($exception->getMessage()), Str::lower($attribute))) {
                    continue;
                }

                throw ValidationException::withMessages([
                    $attribute => 'The '.Str::headline($attribute).' has already been used by a saved record.',
                ]);
            }

            throw $exception;
        }
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeSaved(Builder $query): Builder
    {
        return $query->where('record_status', RecordStatus::Saved);
    }

    public function markAsDraft(): bool
    {
        return $this->forceFill(['record_status' => RecordStatus::Draft])->save();
    }

    public function markAsSaved(): bool
    {
        return $this->forceFill(['record_status' => RecordStatus::Saved])->save();
    }

    /** @return list<string> */
    final public function getLifecycleUniqueAttributes(): array
    {
        return $this->lifecycleUniqueAttributes();
    }

    /** @param array<string, mixed> $data */
    public function getLifecycleUniqueAttributeValue(string $attribute, array $data): mixed
    {
        return $data[$attribute] ?? null;
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return [];
    }

    protected function currentLifecycleActorId(): ?string
    {
        $actor = auth('web')->user();

        if (
            ! $actor instanceof User
            || ! $actor->exists
            || $actor->trashed()
            || ! User::query()->whereKey($actor->getKey())->exists()
        ) {
            return null;
        }

        return (string) $actor->getAuthIdentifier();
    }

    private static function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return Str::contains($message, [
            'unique constraint',
            'duplicate key',
            'duplicate entry',
        ]);
    }
}
