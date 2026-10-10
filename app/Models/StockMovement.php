<?php

namespace App\Models;

use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Enums\StockMutationSource;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\ApprovalWorkflow;
use App\Services\SystemFreezeGuard;
use App\Support\Quantity;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'movement_number',
    'from_warehouse_id',
    'to_warehouse_id',
    'product_variant_id',
    'quantity',
    'note',
    'notes',
    'moved_by',
    'movement_date',
    'approval_status',
    'approval_level',
    'approval_setting_id',
    'status',
    'requested_by',
    'reserved_at',
    'shipped_at',
    'received_at',
    'created_by',
    'updated_by',
    'created_at',
    'updated_at',
    'deleted_by',
])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    public $timestamps = false;

    protected $attributes = [
        'approval_status' => StockMovementApprovalStatus::Pending,
        'status' => StockMovementStatus::Requested,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            if ($movement->status === StockMovementStatus::Draft) {
                $movement->movement_number = null;
            } elseif (
                blank($movement->movement_number)
                || preg_match('/^SM-\d{8}-[A-Z0-9]{12}$/', (string) $movement->movement_number) !== 1
            ) {
                $movement->movement_number = self::savedNumber();
            }

            $movement->movement_date ??= now();
            $movement->created_by ??= auth()->id();

            if (! $movement->isDirty('approval_status')) {
                $requiresApproval = app(ApprovalWorkflow::class)->captureApprovalSetting($movement);
                $movement->approval_status = $movement->status === StockMovementStatus::Draft
                    ? StockMovementApprovalStatus::Pending
                    : ($requiresApproval ? StockMovementApprovalStatus::Requested : StockMovementApprovalStatus::Approved);
                $movement->approval_level = $movement->status === StockMovementStatus::Draft
                    ? 0
                    : ($requiresApproval ? 1 : 0);
            }
        });

        static::updating(function (self $movement): void {
            if ($movement->status === StockMovementStatus::Draft) {
                $movement->movement_number = null;
            } elseif (
                blank($movement->movement_number)
                || preg_match('/^SM-\d{8}-[A-Z0-9]{12}$/', (string) $movement->movement_number) !== 1
            ) {
                $movement->movement_number = self::savedNumber();
            }

            if (
                $movement->getRawOriginal('approval_status') === StockMovementApprovalStatus::Revised->value
                && ($movement->isDirty('status')
                    || $movement->isDirty([
                        'from_warehouse_id',
                        'to_warehouse_id',
                        'requested_by',
                        'movement_date',
                        'notes',
                    ]))
            ) {
                if ($movement->status === StockMovementStatus::Draft) {
                    $movement->approval_status = StockMovementApprovalStatus::Pending;
                    $movement->approval_level = 0;
                } else {
                    $requiresApproval = app(ApprovalWorkflow::class)->requiresApproval($movement);
                    $movement->approval_status = $requiresApproval
                        ? StockMovementApprovalStatus::Requested
                        : StockMovementApprovalStatus::Approved;
                    $movement->approval_level = $requiresApproval ? 1 : 0;
                }
            }

            $movement->updated_at = now();
            $movement->updated_by ??= auth()->id();
        });

        static::deleting(function (self $movement): void {
            if (! $movement->isForceDeleting()) {
                $movement->forceFill(['deleted_by' => auth()->id()])->saveQuietly();
            }
        });

        static::restoring(function (self $movement): void {
            $movement->deleted_by = null;
        });

        static::created(function (self $movement): void {
            $movement->logs()->create([
                'action' => 'created',
                'to_status' => $movement->status->value,
                'changed_by' => $movement->created_by,
                'created_at' => now(),
            ]);

            if ($movement->approval_status === StockMovementApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($movement, $movement->requested_by ?? $movement->created_by);
            }
        });

        static::updated(function (self $movement): void {
            if ($movement->wasChanged('approval_status')
                && $movement->approval_status === StockMovementApprovalStatus::Requested) {
                app(ApprovalWorkflow::class)->recordRequest($movement, $movement->updated_by ?? $movement->requested_by);
            }

            if (! $movement->wasChanged('status')) {
                return;
            }

            $movement->logs()->create([
                'action' => 'status_changed',
                'from_status' => $movement->getOriginal('status'),
                'to_status' => $movement->status->value,
                'changed_by' => $movement->updated_by,
                'created_at' => now(),
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'created_at' => 'datetime',
            'movement_date' => 'datetime',
            'shipped_at' => 'datetime',
            'reserved_at' => 'datetime',
            'received_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'approval_status' => StockMovementApprovalStatus::class,
            'approval_level' => 'integer',
            'status' => StockMovementStatus::class,
        ];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
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

    public function logs(): HasMany
    {
        return $this->hasMany(StockMovementLog::class);
    }

    public function approvalLogs(): MorphMany
    {
        return $this->morphMany(ApprovalLog::class, 'approvable');
    }

    public function approvalSetting(): BelongsTo
    {
        return $this->belongsTo(ApprovalSetting::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockMovementItem::class);
    }

    private static function savedNumber(): string
    {
        do {
            $suffix = Str::upper(Str::substr(
                str_replace('-', '', (string) Str::uuid7()),
                -12,
            ));
            $number = 'SM-'.now()->format('Ymd').'-'.$suffix;
        } while (self::query()->where('movement_number', $number)->exists());

        return $number;
    }

    /**
     * Reserve every requested item at creation time.
     *
     * The timestamp makes this operation safe to retry after a failed UI
     * response without reserving the same movement twice.
     */
    public function reserveRequestedStock(?string $reservedBy = null): self
    {
        if ($this->reserved_at !== null) {
            return $this;
        }

        if (! in_array($this->status, [StockMovementStatus::Requested, StockMovementStatus::Approved], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only requested or approved stock movements can reserve stock.',
            ]);
        }

        return DB::transaction(function () use ($reservedBy): self {
            app(SystemFreezeGuard::class)->assertNotFrozen();

            $movement = self::query()
                ->lockForUpdate()
                ->with(['items', 'fromWarehouse'])
                ->findOrFail($this->id);

            if ($movement->reserved_at !== null) {
                return $movement;
            }

            if (! $movement->fromWarehouse) {
                throw ValidationException::withMessages([
                    'from_warehouse_id' => 'A source warehouse is required before stock can be reserved.',
                ]);
            }

            $reservedBy ??= auth()->id();

            foreach ($movement->items as $item) {
                $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
                $stock = self::lockWarehouseStock($movement->fromWarehouse, $variant);
                $quantity = (string) $item->quantity;

                if ($stock->stock_available < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Insufficient available stock for {$variant->name} (available: {$stock->stock_available}).",
                    ]);
                }

                $stockBefore = (string) $stock->stock_available;
                $stock->forceFill([
                    'stock_available' => Quantity::subtract($stockBefore, $quantity),
                    'stock_reserved' => Quantity::add($stock->stock_reserved, $quantity),
                ])->save();

                if ($variant->stock_available >= $quantity) {
                    $variant->skipStockMutationLogging = true;
                    $variant->forceFill([
                        'stock_available' => Quantity::subtract($variant->stock_available, $quantity),
                        'stock_reserved' => Quantity::add($variant->stock_reserved, $quantity),
                    ])->save();
                }

                self::recordTransferMutation(
                    warehouseId: $movement->from_warehouse_id,
                    variantId: $variant->id,
                    referenceId: $movement->id,
                    stockBefore: $stockBefore,
                    stockAfter: Quantity::subtract($stockBefore, $quantity),
                    quantityChange: Quantity::subtract('0', $quantity),
                    recordedBy: $reservedBy,
                );
            }

            $movement->forceFill([
                'reserved_at' => now(),
                'updated_by' => $reservedBy,
            ])->save();

            return $movement;
        });
    }

    public function canBeCancelled(): bool
    {
        return $this->reserved_at !== null
            && $this->status === StockMovementStatus::Requested
            && (! app(ApprovalWorkflow::class)->requiresApproval($this)
                || $this->approval_status !== StockMovementApprovalStatus::Approved);
    }

    public function isEditableBy(?string $actorId): bool
    {
        return $actorId !== null
            && ! $this->trashed()
            && $this->created_by === $actorId
            && ($this->status === StockMovementStatus::Draft
                || $this->approval_status === StockMovementApprovalStatus::Revised);
    }

    public function submit(?string $submittedBy = null): self
    {
        if ($this->status !== StockMovementStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft stock movements can be submitted.',
            ]);
        }

        return DB::transaction(function () use ($submittedBy): self {
            $movement = self::query()->lockForUpdate()->findOrFail($this->id);
            $requiresApproval = app(ApprovalWorkflow::class)->requiresApproval($movement);
            $movement->forceFill([
                'status' => StockMovementStatus::Requested,
                'approval_status' => $requiresApproval
                    ? StockMovementApprovalStatus::Requested
                    : StockMovementApprovalStatus::Approved,
                'approval_level' => $requiresApproval ? 1 : 0,
                'updated_by' => $submittedBy ?? auth()->id(),
            ])->save();

            return $movement->reserveRequestedStock($submittedBy ?? auth()->id());
        });
    }

    public function cancel(string $reason, ?string $cancelledBy = null): self
    {
        $this->refresh();

        if (! $this->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => 'Only reserved requested movements can be cancelled.',
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A cancellation reason is required.',
            ]);
        }

        return DB::transaction(function () use ($reason, $cancelledBy): self {
            app(SystemFreezeGuard::class)->assertNotFrozen();

            $movement = self::query()
                ->lockForUpdate()
                ->with(['items', 'fromWarehouse'])
                ->findOrFail($this->id);

            if (! $movement->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'status' => 'Only reserved requested movements can be cancelled.',
                ]);
            }

            foreach ($movement->items as $item) {
                $quantity = (string) $item->quantity;
                $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
                $sourceStock = self::lockWarehouseStock($movement->fromWarehouse, $variant);

                if ($sourceStock->stock_reserved < $quantity) {
                    throw ValidationException::withMessages([
                        'status' => "The reserved stock for {$variant->name} is insufficient to cancel this movement.",
                    ]);
                }

                $availableBefore = (string) $sourceStock->stock_available;
                $sourceStock->forceFill([
                    'stock_available' => Quantity::add($availableBefore, $quantity),
                    'stock_reserved' => Quantity::subtract($sourceStock->stock_reserved, $quantity),
                ])->save();

                $variant->skipStockMutationLogging = true;
                $variant->forceFill([
                    'stock_available' => Quantity::add($variant->stock_available, $quantity),
                    'stock_reserved' => max(0, Quantity::subtract($variant->stock_reserved, $quantity)),
                ])->save();

                self::recordTransferMutation(
                    warehouseId: $movement->from_warehouse_id,
                    variantId: $variant->id,
                    referenceId: $movement->id,
                    stockBefore: $availableBefore,
                    stockAfter: Quantity::add($availableBefore, $quantity),
                    quantityChange: $quantity,
                    recordedBy: $cancelledBy ?? auth()->id(),
                    source: StockMutationSource::CancelTransfer,
                );
            }

            $movement->forceFill([
                'status' => StockMovementStatus::Cancelled,
                'approval_status' => StockMovementApprovalStatus::Cancelled,
                'reserved_at' => null,
                'updated_by' => $cancelledBy ?? auth()->id(),
                'notes' => $reason,
            ])->save();

            return $movement;
        });
    }

    public function releaseReservedStock(?string $releasedBy = null): self
    {
        return DB::transaction(function () use ($releasedBy): self {
            $movement = self::query()
                ->lockForUpdate()
                ->with(['items', 'fromWarehouse'])
                ->findOrFail($this->id);

            if ($movement->reserved_at === null) {
                return $movement;
            }

            foreach ($movement->items as $item) {
                $quantity = (string) $item->quantity;
                $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
                $sourceStock = self::lockWarehouseStock($movement->fromWarehouse, $variant);

                if ($sourceStock->stock_reserved < $quantity) {
                    throw ValidationException::withMessages([
                        'status' => "The reserved stock for {$variant->name} is insufficient to release this movement.",
                    ]);
                }

                $availableBefore = (string) $sourceStock->stock_available;
                $sourceStock->forceFill([
                    'stock_available' => Quantity::add($availableBefore, $quantity),
                    'stock_reserved' => Quantity::subtract($sourceStock->stock_reserved, $quantity),
                ])->save();

                $variant->skipStockMutationLogging = true;
                $variant->forceFill([
                    'stock_available' => Quantity::add($variant->stock_available, $quantity),
                    'stock_reserved' => max(0, Quantity::subtract($variant->stock_reserved, $quantity)),
                ])->save();

                self::recordTransferMutation(
                    warehouseId: $movement->from_warehouse_id,
                    variantId: $variant->id,
                    referenceId: $movement->id,
                    stockBefore: $availableBefore,
                    stockAfter: Quantity::add($availableBefore, $quantity),
                    quantityChange: $quantity,
                    recordedBy: $releasedBy ?? auth()->id(),
                );
            }

            return tap($movement->forceFill([
                'reserved_at' => null,
                'updated_by' => $releasedBy ?? auth()->id(),
            ]), fn (self $movement): bool => $movement->save());
        });
    }

    public function markApproved(?string $approvedBy = null): self
    {
        $this->forceFill([
            'status' => StockMovementStatus::Approved,
            'updated_by' => $approvedBy ?? auth()->id(),
        ])->save();

        return $this;
    }

    public function markApprovalRejected(?string $rejectedBy = null): self
    {
        $movement = $this->releaseReservedStock($rejectedBy);

        $movement->forceFill([
            'status' => StockMovementStatus::Cancelled,
            'updated_by' => $rejectedBy ?? auth()->id(),
        ])->save();

        return $movement;
    }

    /**
     * Receive shipped quantities into the destination warehouse.
     *
     * @param  array<int, array{item_id: string, quantity_received: int|string, notes?: string|null}>  $items
     */
    public function receive(array $items, ?string $receivedBy = null, ?string $notes = null): self
    {
        if ($this->status !== StockMovementStatus::Shipped) {
            throw ValidationException::withMessages([
                'status' => 'Only shipped stock movements can be received.',
            ]);
        }

        return DB::transaction(function () use ($items, $receivedBy, $notes): self {
            $movement = self::query()
                ->lockForUpdate()
                ->with(['items', 'fromWarehouse', 'toWarehouse'])
                ->findOrFail($this->id);

            if ($movement->status !== StockMovementStatus::Shipped) {
                throw ValidationException::withMessages([
                    'status' => 'Only shipped stock movements can be received.',
                ]);
            }

            $submittedItems = collect($items)->keyBy('item_id');
            $totalReceived = 0;

            foreach ($movement->items as $item) {
                $submittedItem = $submittedItems->get($item->id);

                if (! $submittedItem) {
                    throw ValidationException::withMessages([
                        'items' => 'Every movement item must have a received quantity.',
                    ]);
                }

                $quantityReceived = Quantity::validated($submittedItem['quantity_received'] ?? null, 'quantity_received', true);
                $quantityShipped = (string) ($item->quantity_shipped ?? 0);

                if ($quantityReceived < 0 || $quantityReceived > $quantityShipped) {
                    throw ValidationException::withMessages([
                        'items' => "The received quantity for {$item->productVariant?->name} must be between 0 and {$quantityShipped}.",
                    ]);
                }

                if (
                    bccomp($quantityReceived, $quantityShipped, 3) !== 0
                    && blank($submittedItem['notes'] ?? null)
                ) {
                    throw ValidationException::withMessages([
                        'items' => "A reason is required when the received quantity for {$item->productVariant?->name} differs from the shipped quantity.",
                    ]);
                }

                if ($quantityShipped > 0 && $movement->reserved_at !== null) {
                    $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
                    $sourceStock = self::lockWarehouseStock($movement->fromWarehouse, $variant);
                    $destinationStock = self::lockWarehouseStock($movement->toWarehouse, $variant);

                    if ($sourceStock->stock_in_transit < $quantityShipped) {
                        throw ValidationException::withMessages([
                            'items' => "The received quantity for {$variant->name} exceeds the in-transit stock.",
                        ]);
                    }

                    $sourceStock->forceFill([
                        'stock_in_transit' => Quantity::subtract($sourceStock->stock_in_transit, $quantityShipped),
                        'stock_out' => Quantity::add($sourceStock->stock_out, $quantityShipped),
                    ])->save();
                    $destinationStock->forceFill([
                        'stock_available' => Quantity::add($destinationStock->stock_available, $quantityReceived),
                    ])->save();

                    if ($variant->stock_in_transit >= $quantityShipped) {
                        $variant->skipStockMutationLogging = true;
                        $variant->forceFill([
                            'stock_in_transit' => Quantity::subtract($variant->stock_in_transit, $quantityShipped),
                            'stock_available' => Quantity::add($variant->stock_available, $quantityReceived),
                        ])->save();
                    }

                    self::recordTransferMutation(
                        warehouseId: $movement->from_warehouse_id,
                        variantId: $variant->id,
                        referenceId: $movement->id,
                        stockBefore: Quantity::add($sourceStock->stock_in_transit, $quantityShipped),
                        stockAfter: $sourceStock->stock_in_transit,
                        quantityChange: Quantity::subtract('0', $quantityShipped),
                        recordedBy: $receivedBy ?? auth()->id(),
                    );
                } elseif ($quantityReceived > 0) {
                    StockMutation::record(
                        variant: $item->productVariant()->firstOrFail(),
                        source: StockMutationSource::Transfer,
                        quantityChange: $quantityReceived,
                        referenceType: self::class,
                        referenceId: $movement->id,
                        recordedBy: $receivedBy ?? auth()->id(),
                        warehouse: $movement->toWarehouse,
                    );
                }

                $item->forceFill([
                    'quantity_received' => $quantityReceived,
                    'notes' => $submittedItem['notes'] ?? $item->notes,
                ])->save();
                $totalReceived = Quantity::add($totalReceived, $quantityReceived);
            }

            if ($totalReceived == 0) {
                throw ValidationException::withMessages([
                    'items' => 'At least one item must have a received quantity greater than zero.',
                ]);
            }

            $movement->forceFill([
                'status' => StockMovementStatus::Received,
                'received_at' => now(),
                'updated_by' => $receivedBy ?? auth()->id(),
                'notes' => filled($notes) ? $notes : $movement->notes,
            ])->save();

            return $movement;
        });
    }

    public function rejectDispatch(string $reason, ?string $rejectedBy = null): self
    {
        if ($this->status !== StockMovementStatus::Shipped) {
            throw ValidationException::withMessages([
                'status' => 'Only shipped stock movements can be rejected.',
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A rejection reason is required.',
            ]);
        }

        return DB::transaction(function () use ($reason, $rejectedBy): self {
            $movement = self::query()
                ->lockForUpdate()
                ->with(['items', 'fromWarehouse'])
                ->findOrFail($this->id);

            if ($movement->status !== StockMovementStatus::Shipped) {
                throw ValidationException::withMessages([
                    'status' => 'Only shipped stock movements can be rejected.',
                ]);
            }

            foreach ($movement->items as $item) {
                $quantityShipped = (string) ($item->quantity_shipped ?? 0);

                if ($quantityShipped > 0 && $movement->reserved_at !== null) {
                    $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
                    $sourceStock = self::lockWarehouseStock($movement->fromWarehouse, $variant);

                    if ($sourceStock->stock_in_transit < $quantityShipped) {
                        throw ValidationException::withMessages([
                            'items' => "The shipped quantity for {$variant->name} exceeds the in-transit stock.",
                        ]);
                    }

                    $sourceStockBefore = (string) $sourceStock->stock_reserved;
                    $sourceStock->forceFill([
                        'stock_in_transit' => Quantity::subtract($sourceStock->stock_in_transit, $quantityShipped),
                        'stock_reserved' => Quantity::add($sourceStock->stock_reserved, $quantityShipped),
                    ])->save();

                    if ($variant->stock_in_transit >= $quantityShipped) {
                        $variant->skipStockMutationLogging = true;
                        $variant->forceFill([
                            'stock_in_transit' => Quantity::subtract($variant->stock_in_transit, $quantityShipped),
                            'stock_reserved' => Quantity::add($variant->stock_reserved, $quantityShipped),
                        ])->save();
                    }

                    self::recordTransferMutation(
                        warehouseId: $movement->from_warehouse_id,
                        variantId: $variant->id,
                        referenceId: $movement->id,
                        stockBefore: $sourceStockBefore,
                        stockAfter: Quantity::add($sourceStockBefore, $quantityShipped),
                        quantityChange: $quantityShipped,
                        recordedBy: $rejectedBy ?? auth()->id(),
                    );
                } elseif ($quantityShipped > 0) {
                    StockMutation::record(
                        variant: $item->productVariant()->firstOrFail(),
                        source: StockMutationSource::Transfer,
                        quantityChange: $quantityShipped,
                        referenceType: self::class,
                        referenceId: $movement->id,
                        recordedBy: $rejectedBy ?? auth()->id(),
                        warehouse: $movement->fromWarehouse,
                    );
                }

                if ($movement->reserved_at !== null && $quantityShipped < $item->quantity) {
                    $quantityToReserve = Quantity::subtract($item->quantity, $quantityShipped);
                    $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
                    $sourceStock = self::lockWarehouseStock($movement->fromWarehouse, $variant);

                    if ($sourceStock->stock_available < $quantityToReserve) {
                        throw ValidationException::withMessages([
                            'items' => "Insufficient available stock to restore the request for {$variant->name}.",
                        ]);
                    }

                    $availableBefore = (string) $sourceStock->stock_available;
                    $sourceStock->forceFill([
                        'stock_available' => Quantity::subtract($availableBefore, $quantityToReserve),
                        'stock_reserved' => Quantity::add($sourceStock->stock_reserved, $quantityToReserve),
                    ])->save();

                    if ($variant->stock_available >= $quantityToReserve) {
                        $variant->skipStockMutationLogging = true;
                        $variant->forceFill([
                            'stock_available' => Quantity::subtract($variant->stock_available, $quantityToReserve),
                            'stock_reserved' => Quantity::add($variant->stock_reserved, $quantityToReserve),
                        ])->save();
                    }

                    self::recordTransferMutation(
                        warehouseId: $movement->from_warehouse_id,
                        variantId: $variant->id,
                        referenceId: $movement->id,
                        stockBefore: $availableBefore,
                        stockAfter: Quantity::subtract($availableBefore, $quantityToReserve),
                        quantityChange: Quantity::subtract('0', $quantityToReserve),
                        recordedBy: $rejectedBy ?? auth()->id(),
                    );
                }

                $item->forceFill([
                    'quantity_shipped' => null,
                    'quantity_received' => null,
                    'notes' => $reason,
                ])->save();
            }

            $movement->forceFill([
                'status' => StockMovementStatus::Requested,
                'shipped_at' => null,
                'received_at' => null,
                'updated_by' => $rejectedBy ?? auth()->id(),
                'notes' => $reason,
            ])->save();

            return $movement;
        });
    }

    /**
     * Ship the requested quantities and deduct them from the source warehouse.
     * The destination warehouse is updated when the movement is received.
     *
     * @param  array<int, array{item_id: string, quantity_shipped: int|string, notes?: string|null}>  $items
     */
    public function ship(array $items, ?string $shippedBy = null, ?string $notes = null): self
    {
        if ($this->approval_status !== StockMovementApprovalStatus::Approved) {
            throw ValidationException::withMessages([
                'approval' => 'This stock movement must be approved before it can be shipped.',
            ]);
        }

        if (! in_array($this->status, [StockMovementStatus::Requested, StockMovementStatus::Approved], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only requested or approved stock movements can be shipped.',
            ]);
        }

        return DB::transaction(function () use ($items, $shippedBy, $notes): self {
            $movement = self::query()
                ->lockForUpdate()
                ->with(['items', 'fromWarehouse'])
                ->findOrFail($this->id);

            if (! in_array($movement->status, [StockMovementStatus::Requested, StockMovementStatus::Approved], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only requested or approved stock movements can be shipped.',
                ]);
            }

            $submittedItems = collect($items)->keyBy('item_id');
            $totalShipped = 0;

            foreach ($movement->items as $item) {
                $submittedItem = $submittedItems->get($item->id);

                if (! $submittedItem) {
                    throw ValidationException::withMessages([
                        'items' => 'Every movement item must have a shipped quantity.',
                    ]);
                }

                $quantityShipped = Quantity::validated($submittedItem['quantity_shipped'] ?? null, 'quantity_shipped', true);

                if ($quantityShipped < 0 || $quantityShipped > $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => "The shipped quantity for {$item->productVariant?->name} must be between 0 and {$item->quantity}.",
                    ]);
                }

                if (
                    bccomp($quantityShipped, $item->quantity, 3) !== 0
                    && blank($submittedItem['notes'] ?? null)
                ) {
                    throw ValidationException::withMessages([
                        'items' => "A reason is required when the shipped quantity for {$item->productVariant?->name} differs from the requested quantity.",
                    ]);
                }

                if ($quantityShipped > 0 && $movement->reserved_at !== null) {
                    $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
                    $sourceStock = self::lockWarehouseStock($movement->fromWarehouse, $variant);

                    if ($sourceStock->stock_reserved < $quantityShipped) {
                        throw ValidationException::withMessages([
                            'items' => "The shipped quantity for {$variant->name} exceeds the reserved stock.",
                        ]);
                    }

                    $sourceStockBefore = (string) $sourceStock->stock_reserved;
                    $sourceStock->forceFill([
                        'stock_reserved' => Quantity::subtract($sourceStockBefore, $quantityShipped),
                        'stock_in_transit' => Quantity::add($sourceStock->stock_in_transit, $quantityShipped),
                    ])->save();

                    if ($variant->stock_reserved >= $quantityShipped) {
                        $variant->skipStockMutationLogging = true;
                        $variant->forceFill([
                            'stock_reserved' => Quantity::subtract($variant->stock_reserved, $quantityShipped),
                            'stock_in_transit' => Quantity::add($variant->stock_in_transit, $quantityShipped),
                        ])->save();
                    }

                    self::recordTransferMutation(
                        warehouseId: $movement->from_warehouse_id,
                        variantId: $variant->id,
                        referenceId: $movement->id,
                        stockBefore: $sourceStockBefore,
                        stockAfter: Quantity::subtract($sourceStockBefore, $quantityShipped),
                        quantityChange: Quantity::subtract('0', $quantityShipped),
                        recordedBy: $shippedBy ?? auth()->id(),
                    );
                } elseif ($quantityShipped > 0) {
                    StockMutation::record(
                        variant: $item->productVariant()->firstOrFail(),
                        source: StockMutationSource::Transfer,
                        quantityChange: Quantity::subtract('0', $quantityShipped),
                        referenceType: self::class,
                        referenceId: $movement->id,
                        recordedBy: $shippedBy ?? auth()->id(),
                        warehouse: $movement->fromWarehouse,
                    );
                }

                if ($movement->reserved_at !== null && $quantityShipped < $item->quantity) {
                    $quantityReleased = Quantity::subtract($item->quantity, $quantityShipped);
                    $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);
                    $sourceStock = self::lockWarehouseStock($movement->fromWarehouse, $variant);

                    if ($sourceStock->stock_reserved < $quantityReleased) {
                        throw ValidationException::withMessages([
                            'items' => "The remaining reserved stock for {$variant->name} is insufficient.",
                        ]);
                    }

                    $availableBefore = (string) $sourceStock->stock_available;
                    $sourceStock->forceFill([
                        'stock_available' => Quantity::add($availableBefore, $quantityReleased),
                        'stock_reserved' => Quantity::subtract($sourceStock->stock_reserved, $quantityReleased),
                    ])->save();

                    $variant->skipStockMutationLogging = true;
                    $variant->forceFill([
                        'stock_available' => Quantity::add($variant->stock_available, $quantityReleased),
                        'stock_reserved' => max(0, Quantity::subtract($variant->stock_reserved, $quantityReleased)),
                    ])->save();

                    self::recordTransferMutation(
                        warehouseId: $movement->from_warehouse_id,
                        variantId: $variant->id,
                        referenceId: $movement->id,
                        stockBefore: $availableBefore,
                        stockAfter: Quantity::add($availableBefore, $quantityReleased),
                        quantityChange: $quantityReleased,
                        recordedBy: $shippedBy ?? auth()->id(),
                    );
                }

                $item->forceFill([
                    'quantity_shipped' => $quantityShipped,
                    'notes' => $submittedItem['notes'] ?? null,
                ])->save();
                $totalShipped = Quantity::add($totalShipped, $quantityShipped);
            }

            if ($totalShipped == 0) {
                throw ValidationException::withMessages([
                    'items' => 'At least one item must have a shipped quantity greater than zero.',
                ]);
            }

            $movement->forceFill([
                'status' => StockMovementStatus::Shipped,
                'shipped_at' => now(),
                'updated_by' => $shippedBy ?? auth()->id(),
                'notes' => filled($notes) ? $notes : $movement->notes,
            ])->save();

            return $movement;
        });
    }

    private static function lockWarehouseStock(Warehouse $warehouse, ProductVariant $variant): WarehouseStock
    {
        WarehouseStock::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id,
            ],
            [
                'stock_available' => 0,
                'stock_reserved' => 0,
                'stock_in_transit' => 0,
                'stock_out' => 0,
            ],
        );

        return WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private static function recordTransferMutation(
        string $warehouseId,
        string $variantId,
        string $referenceId,
        string|int|float $stockBefore,
        string|int|float $stockAfter,
        string|int|float $quantityChange,
        ?string $recordedBy,
        StockMutationSource $source = StockMutationSource::Transfer,
    ): void {
        StockMutation::query()->create([
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $variantId,
            'source' => $source,
            'reference_type' => self::class,
            'reference_id' => $referenceId,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'quantity_change' => $quantityChange,
            'recorded_by' => $recordedBy,
        ]);
    }

    public static function transfer(
        Warehouse $fromWarehouse,
        Warehouse $toWarehouse,
        ProductVariant $variant,
        string|int|float $quantity,
        ?string $note = null,
        ?string $movedBy = null,
    ): self {
        if ($fromWarehouse->is($toWarehouse)) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => 'The destination warehouse must be different from the source warehouse.',
            ]);
        }

        $quantity = Quantity::validated($quantity);
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'The transfer quantity must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use (
            $fromWarehouse,
            $toWarehouse,
            $variant,
            $quantity,
            $note,
            $movedBy,
        ): self {
            app(SystemFreezeGuard::class)->assertNotFrozen();

            $lockedVariant = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $destination = WarehouseStock::query()->firstOrCreate(
                [
                    'warehouse_id' => $toWarehouse->id,
                    'product_variant_id' => $lockedVariant->id,
                ],
                [
                    'stock_available' => 0,
                    'stock_reserved' => 0,
                    'stock_in_transit' => 0,
                    'stock_out' => 0,
                ],
            );

            $stocks = WarehouseStock::query()
                ->whereIn('warehouse_id', [$fromWarehouse->id, $toWarehouse->id])
                ->where('product_variant_id', $lockedVariant->id)
                ->orderBy('warehouse_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('warehouse_id');
            $source = $stocks->get($fromWarehouse->id);
            $destination = $stocks->get($toWarehouse->id) ?? $destination;

            if (! $source || $source->stock_available < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Insufficient available stock in the source warehouse.',
                ]);
            }

            $sourceBefore = $source->stock_available;
            $destinationBefore = $destination->stock_available;
            $source->forceFill([
                'stock_available' => Quantity::subtract($sourceBefore, $quantity),
                'stock_out' => Quantity::add($source->stock_out, $quantity),
            ])->save();
            $destination->forceFill(['stock_available' => Quantity::add($destinationBefore, $quantity)])->save();

            $movement = self::query()->create([
                'approval_status' => StockMovementApprovalStatus::Approved,
                'status' => StockMovementStatus::Received,
                'movement_date' => now(),
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'product_variant_id' => $lockedVariant->id,
                'quantity' => $quantity,
                'note' => $note,
                'notes' => $note,
                'moved_by' => $movedBy ?? auth()->id(),
                'requested_by' => $movedBy ?? auth()->id(),
                'shipped_at' => now(),
                'received_at' => now(),
                'created_by' => $movedBy ?? auth()->id(),
                'created_at' => now(),
            ]);

            StockMutation::query()->create([
                'warehouse_id' => $fromWarehouse->id,
                'product_variant_id' => $lockedVariant->id,
                'source' => StockMutationSource::Transfer,
                'reference_type' => self::class,
                'reference_id' => $movement->id,
                'stock_before' => $sourceBefore,
                'stock_after' => Quantity::subtract($sourceBefore, $quantity),
                'quantity_change' => Quantity::subtract('0', $quantity),
                'recorded_by' => $movedBy ?? auth()->id(),
            ]);

            StockMutation::query()->create([
                'warehouse_id' => $toWarehouse->id,
                'product_variant_id' => $lockedVariant->id,
                'source' => StockMutationSource::Transfer,
                'reference_type' => self::class,
                'reference_id' => $movement->id,
                'stock_before' => $destinationBefore,
                'stock_after' => Quantity::add($destinationBefore, $quantity),
                'quantity_change' => $quantity,
                'recorded_by' => $movedBy ?? auth()->id(),
            ]);

            return $movement;
        });
    }
}
