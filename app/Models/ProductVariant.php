<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\StockMutationSource;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\SystemFreezeGuard;
use App\Support\Quantity;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Sesuai revisi TCS (15 Aug 2026): stok terbagi 3 status terpisah
 * (stock_available, stock_reserved, stock_out) — bukan 1 angka tunggal.
 * Cuma `stock_available` yang boleh diubah manual lewat menu Stocks /
 * Stock Adjustments. stock_reserved & stock_out akan digerakkan otomatis
 * oleh Order System (Tahap 4): buat order -> available turun, reserved
 * naik; order Finished -> reserved turun, stock_out naik; order
 * dibatalkan -> reserved turun, available naik.
 * Convert juga mengurangi available dan menambah stock_out variant asal.
 *
 * Revisi 22 Aug 2026: `initial_stock` itu baseline yang diisi ADMIN
 * SEKALI SAJA waktu bikin Variant — beda dari `stock_available` yang
 * terus berubah. Stock Adjustments TIDAK PERNAH menyentuh initial_stock.
 */
#[Fillable(['product_id', 'unit_id', 'name', 'price', 'price_quantity', 'initial_stock', 'initial_warehouse_id', 'stock_available', 'stock_reserved', 'stock_in_transit', 'stock_out', 'low_stock_threshold', 'is_active'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected $attributes = ['price_quantity' => '1.000'];

    protected function casts(): array
    {
        return [
            'price_quantity' => 'decimal:3',
            'price' => 'decimal:2',
            'initial_stock' => 'decimal:3',
            'stock_available' => 'decimal:3',
            'stock_reserved' => 'decimal:3',
            'stock_in_transit' => 'decimal:3',
            'stock_out' => 'decimal:3',
            'low_stock_threshold' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function inventoryLabel(): Attribute
    {
        return Attribute::get(fn (): string => ($this->product?->name ?? 'Unknown Product').' - '.$this->name);
    }

    public function scopeInventoryVisible(Builder $query): Builder
    {
        return $query
            ->saved()
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery
                ->saved()
                ->where('is_active', true));
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->where('stock_available', '>', 0)
            ->whereColumn('stock_available', '<=', 'low_stock_threshold');
    }

    public function scopeEligibleForStocktake(Builder $query, string $warehouseId): Builder
    {
        $isFarmingWarehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('code', 'WH-FARMING')
            ->exists();

        return $query
            ->inventoryVisible()
            ->where(function (Builder $query) use ($warehouseId, $isFarmingWarehouse): void {
                $query->whereHas('warehouseStocks', fn (Builder $stockQuery): Builder => $stockQuery
                    ->where('warehouse_id', $warehouseId));

                if ($isFarmingWarehouse) {
                    $query->orWhere(function (Builder $legacyQuery): void {
                        $legacyQuery
                            ->where('stock_available', '>', 0)
                            ->whereDoesntHave('warehouseStocks');
                    });
                }
            });
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProductVariantPrice, $this> */
    public function pricePeriods(): HasMany
    {
        return $this->hasMany(ProductVariantPrice::class)->orderByDesc('effective_from');
    }

    /** @return HasMany<WarehouseStock, $this> */
    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    /** @return Collection<int, WarehouseStock> */
    public function salesWarehouseStocks(): Collection
    {
        $stocks = $this->relationLoaded('warehouseStocks')
            ? $this->warehouseStocks
            : $this->warehouseStocks()->with('warehouse')->get();

        if ($stocks->contains(fn (WarehouseStock $stock): bool => ! $stock->relationLoaded('warehouse'))) {
            $stocks->load('warehouse');
        }

        return $stocks
            ->filter(function (WarehouseStock $stock): bool {
                $warehouse = $stock->warehouse;

                return $warehouse !== null
                    && $warehouse->is_active
                    && $warehouse->is_pos
                    && ($warehouse->record_status === RecordStatus::Saved
                        || $warehouse->record_status === RecordStatus::Saved->value);
            })
            ->values();
    }

    public function availableForSale(): string
    {
        $stocks = $this->salesWarehouseStocks();

        if ($stocks->isNotEmpty()) {
            return (string) $stocks->max('stock_available');
        }

        $warehouse = Warehouse::defaultFulfillment();

        return $warehouse === null ? '0.000' : StockMutation::availableForWarehouse($this, $warehouse);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    protected function warehouseSummary(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $stocks = $this->relationLoaded('warehouseStocks')
                    ? $this->warehouseStocks
                    : $this->warehouseStocks()->with('warehouse')->get();

                return $stocks
                    ->map(fn (WarehouseStock $stock): string => $stock->warehouse?->name ?? 'Unknown Warehouse')
                    ->implode(', ');
            },
        );
    }

    protected function warehouseCodeSummary(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $stocks = $this->relationLoaded('warehouseStocks')
                    ? $this->warehouseStocks
                    : $this->warehouseStocks()->with('warehouse')->get();

                return $stocks
                    ->map(fn (WarehouseStock $stock): string => $stock->warehouse?->code ?? '-')
                    ->implode(', ');
            },
        );
    }

    public function currentPrice(?Carbon $date = null): string
    {
        $date ??= today();

        $period = $this->relationLoaded('pricePeriods')
            ? $this->pricePeriods->first(fn (ProductVariantPrice $period): bool => $period->isActiveOn($date))
            : $this->pricePeriods()
                ->whereDate('effective_from', '<=', $date)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
                ->orderByDesc('effective_from')
                ->first();

        return (string) ($period?->price ?? $this->price);
    }

    protected function effectivePrice(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->currentPrice(),
        );
    }

    protected function hasDiscount(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => (float) $this->effective_price < (float) $this->price,
        );
    }

    /**
     * Set to true by StockMutation::record() right before it writes
     * `stock_available` directly, so the generic updated() hook below
     * doesn't log the same change twice (the caller already logs its own,
     * more specific entry).
     */
    public bool $skipStockMutationLogging = false;

    protected static function booted(): void
    {
        static::creating(function (self $variant): void {
            if ($variant->initial_warehouse_id !== null) {
                if (! Warehouse::query()->saved()->where('is_active', true)->whereKey($variant->initial_warehouse_id)->exists()) {
                    throw ValidationException::withMessages(['initial_warehouse_id' => 'Select an active, saved warehouse.']);
                }

                app(SystemFreezeGuard::class)->assertNotFrozen();
            }
            // TCS revision: admin cuma isi "Initial Stock" di form Product.
            // Nilai itu jadi titik awal stock_available JUGA — tapi
            // sesudah ini, initial_stock terkunci selamanya, sementara
            // stock_available bebas berubah lewat Stock Adjustments.
            // Cuma auto-sync kalau stock_available memang belum di-set
            // manual (misal lewat factory buat testing skenario spesifik).
            if (! $variant->isDirty('stock_available')) {
                $variant->stock_available = $variant->initial_stock;
            }
        });

        static::created(function (self $variant): void {
            if ($variant->initial_warehouse_id !== null) {
                $variant->warehouseStocks()->create([
                    'warehouse_id' => $variant->initial_warehouse_id,
                    'stock_available' => $variant->stock_available,
                ]);
            }

            if ($variant->stock_available <= 0) {
                return;
            }

            StockMutation::query()->create([
                'warehouse_id' => $variant->initial_warehouse_id,
                'product_variant_id' => $variant->id,
                'source' => StockMutationSource::InitialStock,
                'stock_before' => 0,
                'stock_after' => $variant->stock_available,
                'quantity_change' => $variant->stock_available,
                'recorded_by' => $variant->created_by,
            ]);
        });

        static::saving(function (self $variant): void {
            $variant->price_quantity = Quantity::validated($variant->getAttributes()['price_quantity'] ?? '1', 'price_quantity');
            if ($variant->exists && $variant->isDirty('initial_warehouse_id')) {
                throw ValidationException::withMessages(['initial_warehouse_id' => 'The initial stock location cannot be changed. Use Stock Movements instead.']);
            }

            if ($variant->exists && $variant->isDirty('stock_available') && ! $variant->skipStockMutationLogging) {
                app(SystemFreezeGuard::class)->assertNotFrozen();
            }
        });

        // Admin can edit `stock_available` directly on the Stocks/Variant
        // form (not just via Stock Adjustments) — that edit must still show
        // up in Stock Mutations for a complete audit trail.
        static::updated(function (self $variant): void {
            if ($variant->skipStockMutationLogging) {
                return;
            }

            if ($variant->wasChanged('stock_available')) {
                $before = (string) $variant->getOriginal('stock_available');
                $after = (string) $variant->stock_available;

                StockMutation::query()->create([
                    'product_variant_id' => $variant->id,
                    'source' => StockMutationSource::ManualAdjustment,
                    'stock_before' => $before,
                    'stock_after' => $after,
                    'quantity_change' => Quantity::subtract($after, $before),
                    'recorded_by' => auth('web')->id(),
                ]);
            }

            if (! $variant->wasChanged([
                'name',
                'unit_id',
                'price',
                'price_quantity',
                'low_stock_threshold',
                'is_active',
            ])) {
                return;
            }

            $variant->product?->forceFill([
                'updated_by' => $variant->updated_by,
            ])->saveQuietly();
        });
    }

    /**
     * TCS revision (20 Aug 2026): 3 status dalam bahasa Inggris —
     * Available (di atas threshold), Low Stock (>0 s/d threshold),
     * Out of Stock (0) — dihitung dari stock_available (stok yang benar-benar
     * bisa dijual sekarang, bukan yang sedang reserved/sudah out).
     *
     * Accessible as either $variant->stockStatus or $variant->stock_status.
     */
    protected function stockStatus(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->stock_available <= 0
                ? 'Out of Stock'
                : ($this->stock_available <= $this->low_stock_threshold ? 'Low Stock' : 'Available'),
        );
    }

    /**
     * True kalau stok masih ada tapi udah di/bawah ambang batas minimum
     * (low_stock_threshold) — dipakai buat kasih alert visual di tabel
     * Stocks.
     */
    protected function isLowStock(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->stock_available > 0 && $this->stock_available <= $this->low_stock_threshold,
        );
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return [];
    }
}
