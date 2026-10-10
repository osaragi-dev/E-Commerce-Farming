<?php

namespace App\Models;

use App\Enums\StockOpnameStatus;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\OrderStockReservationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'key',
    'is_frozen',
    'frozen_session_id',
    'frozen_by',
    'frozen_at',
    'unfrozen_by',
    'unfrozen_at',
])]
class SystemFreezeState extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'is_frozen' => 'boolean',
            'frozen_at' => 'datetime',
            'unfrozen_at' => 'datetime',
        ];
    }

    public function frozenSession(): BelongsTo
    {
        return $this->belongsTo(StockOpnameSession::class, 'frozen_session_id');
    }

    public function frozenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by');
    }

    public function unfrozenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unfrozen_by');
    }

    public static function current(bool $lock = false): self
    {
        $query = self::query()->where('key', 'system');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public static function activate(?StockOpnameSession $session = null, ?string $actorId = null): self
    {
        return DB::transaction(function () use ($session, $actorId): self {
            $state = self::current(lock: true);

            if ($state->is_frozen) {
                throw ValidationException::withMessages([
                    'system_freeze' => 'The system is already frozen.',
                ]);
            }

            if ($session !== null) {
                $session->refresh();

                if (! in_array($session->status, [StockOpnameStatus::Draft, StockOpnameStatus::Counting], true)) {
                    throw ValidationException::withMessages([
                        'status' => 'Only draft or counting stock opnames can freeze the system.',
                    ]);
                }
            }

            $state->forceFill([
                'is_frozen' => true,
                'frozen_session_id' => $session?->id,
                'frozen_by' => $actorId ?? auth()->id(),
                'frozen_at' => now(),
                'unfrozen_by' => null,
                'unfrozen_at' => null,
            ])->save();

            if ($session !== null) {
                $session->forceFill([
                    'status' => StockOpnameStatus::Frozen,
                    'updated_by' => $actorId ?? auth()->id(),
                ])->save();
            }

            return $state;
        });
    }

    public static function deactivate(?string $actorId = null): self
    {
        return DB::transaction(function () use ($actorId): self {
            $state = self::current(lock: true);

            if (! $state->is_frozen) {
                throw ValidationException::withMessages([
                    'system_freeze' => 'The system is not frozen.',
                ]);
            }

            $session = $state->frozen_session_id
                ? StockOpnameSession::query()->lockForUpdate()->find($state->frozen_session_id)
                : null;

            $state->forceFill([
                'is_frozen' => false,
                'unfrozen_by' => $actorId ?? auth()->id(),
                'unfrozen_at' => now(),
            ])->save();

            if ($session?->status === StockOpnameStatus::Frozen) {
                $session->forceFill([
                    'status' => StockOpnameStatus::Counting,
                    'updated_by' => $actorId ?? auth()->id(),
                ])->save();
            }

            app(OrderStockReservationService::class)->processPending($actorId ?? auth()->id());

            return $state;
        });
    }
}
