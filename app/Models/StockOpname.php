<?php

namespace App\Models;

use App\Enums\StockMutationSource;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Support\Quantity;
use Database\Factories\StockOpnameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable(['warehouse_id', 'product_variant_id', 'system_stock', 'counted_stock', 'difference', 'note', 'counted_by'])]
class StockOpname extends Model
{
    /** @use HasFactory<StockOpnameFactory> */
    use HasFactory, HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'system_stock' => 'decimal:3',
            'counted_stock' => 'decimal:3',
            'difference' => 'decimal:3',
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

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public static function record(
        Warehouse $warehouse,
        ProductVariant $variant,
        string|int|float $countedStock,
        ?string $note = null,
        ?string $countedBy = null,
    ): self {
        $countedStock = Quantity::validated($countedStock, 'counted_stock', true);
        if ($countedStock < 0) {
            throw ValidationException::withMessages([
                'counted_stock' => 'The counted stock cannot be negative.',
            ]);
        }

        return DB::transaction(function () use (
            $warehouse, $variant, $countedStock, $note, $countedBy,
        ): self {
            $lockedVariant = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $stock = WarehouseStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_variant_id', $lockedVariant->id)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                $stock = WarehouseStock::query()->create([
                    'warehouse_id' => $warehouse->id,
                    'product_variant_id' => $lockedVariant->id,
                    'stock_available' => StockMutation::availableForWarehouse($lockedVariant, $warehouse),
                    'stock_reserved' => 0,
                    'stock_in_transit' => 0,
                    'stock_out' => 0,
                ]);
            }

            $systemStock = $stock?->stock_available ?? 0;
            $difference = Quantity::subtract($countedStock, $systemStock);

            $opname = self::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_variant_id' => $lockedVariant->id,
                'system_stock' => $systemStock,
                'counted_stock' => $countedStock,
                'difference' => $difference,
                'note' => $note,
                'counted_by' => $countedBy ?? auth()->id(),
            ]);

            StockMutation::record(
                variant: $lockedVariant,
                source: StockMutationSource::Stocktake,
                quantityChange: $difference,
                referenceType: self::class,
                referenceId: $opname->id,
                recordedBy: $countedBy ?? auth()->id(),
                warehouse: $warehouse,
            );

            return $opname;
        });
    }
}
