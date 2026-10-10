<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use App\Observers\WarehouseStockObserver;
use App\Support\Quantity;
use Database\Factories\WarehouseStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['warehouse_id', 'product_variant_id', 'stock_available', 'stock_reserved', 'stock_in_transit', 'stock_out'])]
#[ObservedBy(WarehouseStockObserver::class)]
class WarehouseStock extends Model
{
    /** @use HasFactory<WarehouseStockFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'stock_available' => 'decimal:3',
            'stock_reserved' => 'decimal:3',
            'stock_in_transit' => 'decimal:3',
            'stock_out' => 'decimal:3',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function scopeLowStock(Builder $query): Builder
    {
        $warehouseStocksTable = (new self)->getTable();
        $productVariantsTable = (new ProductVariant)->getTable();

        return $query
            ->where('stock_available', '>', 0)
            ->whereHas('productVariant', fn (Builder $variantQuery): Builder => $variantQuery
                ->whereColumn(
                    "{$warehouseStocksTable}.stock_available",
                    '<=',
                    "{$productVariantsTable}.low_stock_threshold",
                ));
    }

    public function scopeAvailableStock(Builder $query): Builder
    {
        $warehouseStocksTable = $this->getTable();
        $productVariantsTable = (new ProductVariant)->getTable();

        return $query
            ->where('stock_available', '>', 0)
            ->whereHas('productVariant', fn (Builder $variantQuery): Builder => $variantQuery
                ->whereColumn(
                    "{$warehouseStocksTable}.stock_available",
                    '>',
                    "{$productVariantsTable}.low_stock_threshold",
                ));
    }

    public function scopeOutOfStock(Builder $query): Builder
    {
        return $query->where('stock_available', '<=', 0);
    }

    public function scopeCriticalStock(Builder $query): Builder
    {
        return $query->where(fn (Builder $stockQuery): Builder => $stockQuery
            ->outOfStock()
            ->orWhere(fn (Builder $lowStockQuery): Builder => $lowStockQuery->lowStock()));
    }

    protected function totalStock(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Quantity::add(Quantity::add($this->stock_available, $this->stock_reserved), Quantity::add($this->stock_in_transit, $this->stock_out)),
        );
    }

    protected function stockStatus(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $threshold = (string) ($this->productVariant?->low_stock_threshold ?? 0);

                return $this->stock_available <= 0
                    ? 'Out of Stock'
                    : ($this->stock_available <= $threshold ? 'Low Stock' : 'Available');
            },
        );
    }

    protected function isLowStock(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->stock_available > 0
                && $this->stock_available <= (string) ($this->productVariant?->low_stock_threshold ?? 0),
        );
    }
}
