<?php

namespace App\Models;

use App\Enums\StockMutationSource;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\SystemFreezeGuard;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read Only audit trail (Bab 22) — dibuat lewat StockMutation::record(),
 * bukan lewat form Filament. Append-only, tidak pernah diedit.
 */
#[Fillable(['warehouse_id', 'product_variant_id', 'source', 'reference_type', 'reference_id', 'stock_before', 'stock_after', 'quantity_change', 'recorded_by'])]
class StockMutation extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $attributes = [
        'created_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'source' => StockMutationSource::class,
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'quantity_change' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $mutation): void {
            $mutation->created_at ??= now();
        });
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'reference_id');
    }

    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'reference_id')->withTrashed();
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reference_id')->withTrashed();
    }

    public function repack(): BelongsTo
    {
        return $this->belongsTo(Repack::class, 'reference_id')->withTrashed();
    }

    public function stockWaste(): BelongsTo
    {
        return $this->belongsTo(StockWaste::class, 'reference_id')->withTrashed();
    }

    /**
     * Satu-satunya cara resmi bikin mutasi stok manual: mengubah
     * stock_available di ProductVariant DAN mencatat before/after-nya
     * sekaligus, dalam 1 transaction supaya tidak pernah nyangkut setengah
     * jalan. Cuma untuk bucket "available" — mutasi reserved/out akibat
     * Order System (Tahap 4) akan pakai jalur terpisah nanti.
     */
    public static function record(
        ProductVariant $variant,
        StockMutationSource $source,
        string|int|float $quantityChange,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $recordedBy = null,
        ?Warehouse $warehouse = null,
    ): self {
        return DB::transaction(function () use (
            $variant, $source, $quantityChange, $referenceType, $referenceId, $recordedBy, $warehouse,
        ) {
            if ($warehouse) {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            }

            if ($source !== StockMutationSource::Stocktake) {
                app(SystemFreezeGuard::class)->assertNotFrozen();
            }

            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $stock = $warehouse
                ? self::warehouseStock($variant, $warehouse)
                : null;
            $before = $stock?->stock_available ?? $variant->stock_available;
            $after = Quantity::add($before, $quantityChange);

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient available stock (available: {$before}).",
                ]);
            }

            if ($stock) {
                $stock->forceFill(['stock_available' => $after])->save();
            }

            $variant->skipStockMutationLogging = true;
            $variant->forceFill(['stock_available' => Quantity::add($variant->stock_available, $quantityChange)])->save();

            return self::query()->create([
                'warehouse_id' => $warehouse?->id,
                'product_variant_id' => $variant->id,
                'source' => $source,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'stock_before' => $before,
                'stock_after' => $after,
                'quantity_change' => $quantityChange,
                'recorded_by' => $recordedBy,
            ]);
        });
    }

    public static function moveAvailableToOut(
        ProductVariant $variant,
        string|int|float $quantity,
        string $referenceType,
        string $referenceId,
        ?string $recordedBy,
        Warehouse $warehouse,
    ): self {
        return self::moveBetweenBuckets(
            $variant,
            $quantity,
            'stock_available',
            'stock_out',
            StockMutationSource::StockWaste,
            $referenceType,
            $referenceId,
            $recordedBy,
            $warehouse,
        );
    }

    public static function moveOutToAvailable(
        ProductVariant $variant,
        string|int|float $quantity,
        string $referenceType,
        string $referenceId,
        ?string $recordedBy,
        Warehouse $warehouse,
    ): self {
        return self::moveBetweenBuckets(
            $variant,
            $quantity,
            'stock_out',
            'stock_available',
            StockMutationSource::CancelStockWaste,
            $referenceType,
            $referenceId,
            $recordedBy,
            $warehouse,
        );
    }

    /** @param 'stock_available'|'stock_out' $fromBucket
     * @param  'stock_available'|'stock_out'  $toBucket
     */
    private static function moveBetweenBuckets(
        ProductVariant $variant,
        string|int|float $quantity,
        string $fromBucket,
        string $toBucket,
        StockMutationSource $source,
        string $referenceType,
        string $referenceId,
        ?string $recordedBy,
        Warehouse $warehouse,
    ): self {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Stock movement quantity must be greater than zero.');
        }

        $quantity = Quantity::validated($quantity);

        return DB::transaction(function () use (
            $variant,
            $quantity,
            $fromBucket,
            $toBucket,
            $source,
            $referenceType,
            $referenceId,
            $recordedBy,
            $warehouse,
        ): self {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            app(SystemFreezeGuard::class)->assertNotFrozen();

            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $stock = self::warehouseStock($variant, $warehouse);
            $stock = WarehouseStock::query()->lockForUpdate()->findOrFail($stock->id);

            if ($variant->{$fromBucket} < $quantity || $stock->{$fromBucket} < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Insufficient {$fromBucket} stock to record this waste.",
                ]);
            }

            $stockAvailableBefore = (string) $stock->stock_available;
            $stockAvailableAfter = $fromBucket === 'stock_available'
                ? Quantity::subtract($stockAvailableBefore, $quantity)
                : Quantity::add($stockAvailableBefore, $quantity);
            $quantityChange = $fromBucket === 'stock_available'
                ? Quantity::subtract('0', $quantity)
                : $quantity;

            $stock->forceFill([
                $fromBucket => Quantity::subtract($stock->{$fromBucket}, $quantity),
                $toBucket => Quantity::add($stock->{$toBucket}, $quantity),
            ])->save();

            $variant->skipStockMutationLogging = true;
            $variant->forceFill([
                $fromBucket => Quantity::subtract($variant->{$fromBucket}, $quantity),
                $toBucket => Quantity::add($variant->{$toBucket}, $quantity),
            ])->save();

            return self::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id,
                'source' => $source,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'stock_before' => $stockAvailableBefore,
                'stock_after' => $stockAvailableAfter,
                'quantity_change' => $quantityChange,
                'recorded_by' => $recordedBy,
            ]);
        });
    }

    private static function warehouseStock(
        ProductVariant $variant,
        Warehouse $warehouse,
        bool $seedFromLegacy = false,
    ): WarehouseStock {
        $hasDistributedStock = WarehouseStock::query()
            ->where('product_variant_id', $variant->id)
            ->exists();

        return WarehouseStock::query()->firstOrCreate(
            [
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $variant->id,
            ],
            [
                'stock_available' => $warehouse->code === 'WH-FARMING' || ($seedFromLegacy && ! $hasDistributedStock)
                    ? $variant->stock_available
                    : 0,
                'stock_reserved' => 0,
                'stock_in_transit' => 0,
                'stock_out' => 0,
            ],
        );
    }

    public static function availableForWarehouse(ProductVariant $variant, Warehouse $warehouse): string
    {
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_variant_id', $variant->id)
            ->value('stock_available');

        if ($stock !== null) {
            return (string) $stock;
        }

        $hasDistributedStock = WarehouseStock::query()
            ->where('product_variant_id', $variant->id)
            ->exists();

        return $hasDistributedStock ? '0.000' : (string) $variant->stock_available;
    }

    /** Reserve stock for an order. */
    public static function reserveForOrder(
        ProductVariant $variant,
        string|int|float $quantity,
        string $orderItemId,
        ?string $actorId,
        ?Warehouse $warehouse = null,
    ): self {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Stock reservation quantity must be greater than zero.');
        }

        if ($warehouse
            ? self::availableForWarehouse($variant, $warehouse) < $quantity
            : $variant->stock_available < $quantity
        ) {
            throw new \InvalidArgumentException('Insufficient available stock for reservation.');
        }

        $quantity = Quantity::validated($quantity);

        return DB::transaction(function () use ($variant, $quantity, $orderItemId, $actorId, $warehouse) {
            if ($warehouse) {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            }

            app(SystemFreezeGuard::class)->assertNotFrozen();

            $stock = $warehouse
                ? self::warehouseStock($variant, $warehouse, seedFromLegacy: true)
                : null;
            if ($stock) {
                $stock = WarehouseStock::query()->lockForUpdate()->findOrFail($stock->id);
                if ($stock->stock_available < $quantity) {
                    throw new \InvalidArgumentException('Insufficient available warehouse stock for reservation.');
                }
            }

            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            if ($variant->stock_available < $quantity) {
                throw new \InvalidArgumentException('Insufficient available stock for reservation.');
            }
            $before = $variant->stock_available;
            $after = Quantity::subtract($before, $quantity);

            if ($stock) {
                $stock->forceFill([
                    'stock_available' => Quantity::subtract($stock->stock_available, $quantity),
                    'stock_reserved' => Quantity::add($stock->stock_reserved, $quantity),
                ])->save();
            }

            $variant->skipStockMutationLogging = true;
            $variant->forceFill([
                'stock_available' => $after,
                'stock_reserved' => Quantity::add($variant->stock_reserved, $quantity),
            ])->save();

            return self::query()->create([
                'product_variant_id' => $variant->id,
                'warehouse_id' => $warehouse?->id,
                'source' => StockMutationSource::Order,
                'reference_type' => OrderItem::class,
                'reference_id' => $orderItemId,
                'stock_before' => $before,
                'stock_after' => $after,
                'quantity_change' => Quantity::subtract('0', $quantity),
                'recorded_by' => $actorId,
            ]);
        });
    }

    /** Return reserved, shipped, or fulfilled stock for a cancelled order. */
    public static function releaseFromOrder(
        ProductVariant $variant,
        string|int|float $quantity,
        string $orderItemId,
        ?string $actorId,
        ?Warehouse $warehouse = null,
        bool $fulfilled = false,
    ): self {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Stock release quantity must be greater than zero.');
        }

        $quantity = Quantity::validated($quantity);

        return DB::transaction(function () use ($variant, $quantity, $orderItemId, $actorId, $warehouse, $fulfilled) {
            if ($warehouse) {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            }

            app(SystemFreezeGuard::class)->assertNotFrozen();

            $stock = $warehouse
                ? WarehouseStock::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $fromBucket = self::query()->where('source', StockMutationSource::OrderShipped)
                ->where('reference_type', OrderItem::class)->where('reference_id', $orderItemId)->exists()
                ? 'stock_in_transit' : 'stock_reserved';
            if ($fulfilled) {
                $fromBucket = 'stock_out';
            }

            if (self::query()->where('source', StockMutationSource::CancelOrder)
                ->where('reference_type', OrderItem::class)->where('reference_id', $orderItemId)->exists()) {
                throw new \InvalidArgumentException('Stock has already been returned for this order item.');
            }

            if ($variant->{$fromBucket} < $quantity || ($warehouse && (! $stock || $stock->{$fromBucket} < $quantity))) {
                throw new \InvalidArgumentException('Insufficient stock to cancel this order.');
            }
            $before = $variant->stock_available;
            $after = Quantity::add($before, $quantity);

            if ($stock) {
                $stock->forceFill([
                    'stock_available' => Quantity::add($stock->stock_available, $quantity),
                    $fromBucket => Quantity::subtract($stock->{$fromBucket}, $quantity),
                ])->save();
            }

            $variant->skipStockMutationLogging = true;
            $variant->forceFill([
                'stock_available' => $after,
                $fromBucket => Quantity::subtract($variant->{$fromBucket}, $quantity),
            ])->save();

            return self::query()->create([
                'product_variant_id' => $variant->id,
                'warehouse_id' => $warehouse?->id,
                'source' => StockMutationSource::CancelOrder,
                'reference_type' => OrderItem::class,
                'reference_id' => $orderItemId,
                'stock_before' => $before,
                'stock_after' => $after,
                'quantity_change' => $quantity,
                'recorded_by' => $actorId,
            ]);
        });
    }

    public static function fulfillForOrder(
        ProductVariant $variant,
        string|int|float $quantity,
        string $orderItemId,
        ?string $actorId,
        ?Warehouse $warehouse = null,
    ): void {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Stock fulfillment quantity must be greater than zero.');
        }

        $quantity = Quantity::validated($quantity);

        DB::transaction(function () use ($variant, $quantity, $orderItemId, $warehouse): void {
            if ($warehouse) {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            }

            app(SystemFreezeGuard::class)->assertNotFrozen();

            $wasShipped = self::query()
                ->where('source', StockMutationSource::OrderShipped)
                ->where('reference_type', OrderItem::class)
                ->where('reference_id', $orderItemId)
                ->exists();
            $fromBucket = $wasShipped ? 'stock_in_transit' : 'stock_reserved';
            $lockedVariant = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $stock = $warehouse
                ? WarehouseStock::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($lockedVariant->{$fromBucket} < $quantity || ($warehouse && (! $stock || $stock->{$fromBucket} < $quantity))) {
                throw new \InvalidArgumentException('Insufficient stock to complete this order.');
            }

            if ($stock) {
                $stock->forceFill([
                    $fromBucket => Quantity::subtract($stock->{$fromBucket}, $quantity),
                    'stock_out' => Quantity::add($stock->stock_out, $quantity),
                ])->save();
            }

            $lockedVariant->skipStockMutationLogging = true;
            $lockedVariant->forceFill([
                $fromBucket => Quantity::subtract($lockedVariant->{$fromBucket}, $quantity),
                'stock_out' => Quantity::add($lockedVariant->stock_out, $quantity),
            ])->save();
        });
    }

    public static function shipForOrder(
        ProductVariant $variant,
        string|int|float $quantity,
        string $orderItemId,
        ?string $actorId,
        ?Warehouse $warehouse = null,
    ): void {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Stock shipping quantity must be greater than zero.');
        }

        $quantity = Quantity::validated($quantity);

        DB::transaction(function () use ($variant, $quantity, $orderItemId, $actorId, $warehouse): void {
            if ($warehouse) {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            }

            app(SystemFreezeGuard::class)->assertNotFrozen();

            $lockedVariant = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $stock = $warehouse
                ? WarehouseStock::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_variant_id', $variant->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($lockedVariant->stock_reserved < $quantity || ($warehouse && (! $stock || $stock->stock_reserved < $quantity))) {
                throw new \InvalidArgumentException('Insufficient reserved stock to ship this order.');
            }

            if ($stock) {
                $stock->forceFill([
                    'stock_reserved' => Quantity::subtract($stock->stock_reserved, $quantity),
                    'stock_in_transit' => Quantity::add($stock->stock_in_transit, $quantity),
                ])->save();
            }

            $lockedVariant->skipStockMutationLogging = true;
            $lockedVariant->forceFill([
                'stock_reserved' => Quantity::subtract($lockedVariant->stock_reserved, $quantity),
                'stock_in_transit' => Quantity::add($lockedVariant->stock_in_transit, $quantity),
            ])->save();

            self::query()->create([
                'product_variant_id' => $variant->id,
                'warehouse_id' => $warehouse?->id,
                'source' => StockMutationSource::OrderShipped,
                'reference_type' => OrderItem::class,
                'reference_id' => $orderItemId,
                'stock_before' => $lockedVariant->stock_available,
                'stock_after' => $lockedVariant->stock_available,
                'quantity_change' => 0,
                'recorded_by' => $actorId,
            ]);
        });
    }
}
