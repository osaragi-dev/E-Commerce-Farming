<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Enums\StockMutationSource;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use App\Services\SystemFreezeGuard;
use App\Support\Quantity;
use Database\Factories\RepackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable(['warehouse_id', 'from_product_variant_id', 'to_product_variant_id', 'from_quantity', 'to_quantity', 'notes'])]
class Repack extends Model
{
    /** @use HasFactory<RepackFactory> */
    use HasFactory, HasUuidPrimaryKey, SoftDeletes;

    use HasRecordLifecycle { save as private lifecycleSave; }

    private bool $deletingDraft = false;

    protected function casts(): array
    {
        return ['from_quantity' => 'decimal:3', 'to_quantity' => 'decimal:3', 'applied_at' => 'datetime', 'stock_out_applied_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        if ($this->deletingDraft) {
            return $this->lifecycleSave($options);
        }

        return DB::transaction(function () use ($options): bool {
            if ($this->exists) {
                $current = self::withTrashed()->lockForUpdate()->findOrFail($this->id);
                if ($current->cancelled_at !== null) {
                    throw ValidationException::withMessages(['record_status' => 'Cancelled Convert transactions cannot be changed or submitted.']);
                }
                if ($current->applied_at !== null) {
                    foreach ($this->getDirty() as $attribute => $value) {
                        if ($value !== ($current->getAttributes()[$attribute] ?? null)) {
                            throw ValidationException::withMessages(['record_status' => 'Saved repacks cannot be changed.']);
                        }
                    }
                    $this->setRawAttributes($current->getAttributes(), true);
                    $this->unsetRelations();

                    return true;
                }
                if ($current->trashed() && $this->deleted_at !== null) {
                    throw ValidationException::withMessages(['record_status' => 'Restore this draft before editing it.']);
                }
            }

            app(SystemFreezeGuard::class)->assertNotFrozen();
            $warehouse = Warehouse::query()->saved()->where('is_active', true)->lockForUpdate()->find($this->warehouse_id);
            if (! $warehouse) {
                throw ValidationException::withMessages(['warehouse_id' => 'Select an active, saved location.']);
            }
            if ($this->from_product_variant_id === $this->to_product_variant_id) {
                throw ValidationException::withMessages(['to_product_variant_id' => 'Choose a different target item.']);
            }
            $variants = ProductVariant::query()->inventoryVisible()->with('unit')
                ->whereIn('id', [$this->from_product_variant_id, $this->to_product_variant_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach (['from', 'to'] as $side) {
                $variant = $variants->get($this->getAttribute($side.'_product_variant_id'));
                if (! $variant || ! $variant->unit) {
                    throw ValidationException::withMessages([$side.'_product_variant_id' => 'Select an active, saved item with a unit.']);
                }
                $this->setAttribute($side.'_quantity', Quantity::validated($this->getAttributes()[$side.'_quantity'] ?? null, $side.'_quantity'));
                $this->setAttribute($side.'_unit', $variant->unit->name);
            }
            $this->record_status ??= RecordStatus::Saved;
            if (! in_array($this->record_status, [RecordStatus::Draft, RecordStatus::Saved], true)) {
                throw ValidationException::withMessages(['record_status' => 'Select Draft or Saved.']);
            }
            $from = $variants->get($this->from_product_variant_id);
            $to = $variants->get($this->to_product_variant_id);
            if ($this->record_status === RecordStatus::Saved) {
                if (bccomp(self::availableAt($from, $warehouse), $this->from_quantity, 3) < 0) {
                    throw ValidationException::withMessages(['from_quantity' => 'Insufficient available stock at this location.']);
                }
                foreach ([$from, $to] as $variant) {
                    WarehouseStock::query()->firstOrCreate(
                        ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id],
                        ['stock_available' => self::availableAt($variant, $warehouse), 'stock_reserved' => 0, 'stock_in_transit' => 0, 'stock_out' => 0],
                    );
                }
                $this->repack_number = $this->nextNumber();
            } else {
                $this->repack_number = null;
            }
            $saved = $this->lifecycleSave($options);
            if ($saved && $this->record_status === RecordStatus::Saved) {
                foreach ([[$from, Quantity::subtract('0', $this->from_quantity)], [$to, $this->to_quantity]] as [$variant, $quantity]) {
                    StockMutation::record($variant, StockMutationSource::Repack, $quantity, self::class, $this->id, auth()->id() ?? $this->created_by, $warehouse);
                }
                $this->forceFill(['applied_at' => now()]);
                DB::table('repacks')->where('id', $this->id)->update(['applied_at' => $this->applied_at]);
                $this->syncOriginalAttribute('applied_at');
                $this->applyStockOut();
            }

            return $saved;
        });
    }

    public function applyStockOut(): bool
    {
        return DB::transaction(function (): bool {
            $record = self::withTrashed()->lockForUpdate()->findOrFail($this->id);
            if ($record->applied_at === null || $record->stock_out_applied_at !== null || $record->cancelled_at !== null) {
                return false;
            }

            $warehouse = DB::table('warehouses')->where('id', $record->warehouse_id)->lockForUpdate()->first();
            if (! $warehouse) {
                throw ValidationException::withMessages(['warehouse_id' => 'Convert source warehouse was not found.']);
            }

            $variant = DB::table('product_variants')->where('id', $record->from_product_variant_id)->lockForUpdate()->first();
            $stock = DB::table('warehouse_stocks')->where('warehouse_id', $record->warehouse_id)
                ->where('product_variant_id', $record->from_product_variant_id)->lockForUpdate()->first();
            if (! $variant || ! $stock) {
                throw ValidationException::withMessages(['from_product_variant_id' => 'Convert source variant or warehouse stock was not found.']);
            }

            $quantity = Quantity::validated($record->from_quantity, 'from_quantity');
            DB::table('product_variants')->where('id', $variant->id)->update([
                'stock_out' => Quantity::add((string) $variant->stock_out, $quantity),
            ]);
            DB::table('warehouse_stocks')->where('id', $stock->id)->update([
                'stock_out' => Quantity::add((string) $stock->stock_out, $quantity),
            ]);
            $this->stock_out_applied_at = now();
            DB::table('repacks')->where('id', $record->id)->update(['stock_out_applied_at' => $this->stock_out_applied_at]);
            $this->syncOriginalAttribute('stock_out_applied_at');

            return true;
        });
    }

    protected function displayStatus(): Attribute
    {
        return Attribute::get(fn (): string => $this->cancelled_at !== null ? 'Cancelled' : $this->record_status->getLabel());
    }

    public function isCancellable(): bool
    {
        return ! $this->trashed() && $this->record_status === RecordStatus::Saved
            && $this->applied_at !== null && $this->cancelled_at === null;
    }

    public function cancel(?string $actorId = null): void
    {
        DB::transaction(function () use ($actorId): void {
            $record = self::withTrashed()->lockForUpdate()->findOrFail($this->id);
            if ($record->cancelled_at !== null) {
                $this->setRawAttributes($record->getAttributes(), true);
                $this->unsetRelations();

                return;
            }
            if (! $record->isCancellable()) {
                throw ValidationException::withMessages(['record_status' => 'Only applied, saved Convert transactions can be cancelled.']);
            }
            app(SystemFreezeGuard::class)->assertNotFrozen();
            $warehouse = Warehouse::query()->lockForUpdate()->find($record->warehouse_id);
            $variants = ProductVariant::query()->whereIn('id', [$record->from_product_variant_id, $record->to_product_variant_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $variants->get($record->from_product_variant_id);
            $to = $variants->get($record->to_product_variant_id);
            $stocks = WarehouseStock::query()->where('warehouse_id', $record->warehouse_id)
                ->whereIn('product_variant_id', [$record->from_product_variant_id, $record->to_product_variant_id])
                ->orderBy('product_variant_id')->lockForUpdate()->get()->keyBy('product_variant_id');
            if (! $warehouse || ! $from || ! $to || $stocks->count() !== 2) {
                throw ValidationException::withMessages(['record_status' => 'Convert warehouse, variants, or warehouse stock could not be found.']);
            }
            if (bccomp($stocks->get($to->id)->stock_available, $record->to_quantity, 3) < 0
                || bccomp($to->stock_available, $record->to_quantity, 3) < 0) {
                throw ValidationException::withMessages(['to_quantity' => 'Insufficient available target stock to cancel this Convert.']);
            }
            $record->applyStockOut();
            $from->refresh();
            $sourceStock = $stocks->get($from->id)->refresh();
            if (bccomp($from->stock_out, $record->from_quantity, 3) < 0
                || bccomp($sourceStock->stock_out, $record->from_quantity, 3) < 0) {
                throw ValidationException::withMessages(['from_quantity' => 'Insufficient source Out to cancel this Convert.']);
            }
            $actorId ??= auth()->id();
            StockMutation::record($to, StockMutationSource::CancelRepack, Quantity::subtract('0', $record->to_quantity), self::class, $record->id, $actorId, $warehouse);
            StockMutation::record($from, StockMutationSource::CancelRepack, $record->from_quantity, self::class, $record->id, $actorId, $warehouse);
            DB::table('product_variants')->where('id', $from->id)->update(['stock_out' => Quantity::subtract($from->stock_out, $record->from_quantity)]);
            DB::table('warehouse_stocks')->where('id', $sourceStock->id)->update(['stock_out' => Quantity::subtract($sourceStock->stock_out, $record->from_quantity)]);
            DB::table('repacks')->where('id', $record->id)->update([
                'cancelled_at' => now(), 'cancelled_by' => $actorId, 'updated_at' => now(), 'updated_by' => $actorId,
            ]);
            $this->refresh();
        });
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public static function availableAt(ProductVariant $variant, Warehouse $warehouse): string
    {
        if ($variant->relationLoaded('warehouseStocks')) {
            $stocks = $variant->warehouseStocks;

            return (string) ($stocks->firstWhere('warehouse_id', $warehouse->id)?->stock_available
                ?? ($warehouse->code === 'WH-FARMING' && $stocks->isEmpty() ? $variant->stock_available : '0'));
        }

        $available = $variant->warehouseStocks()->where('warehouse_id', $warehouse->id)->value('stock_available');

        return (string) ($available ?? ($warehouse->code === 'WH-FARMING' && ! $variant->warehouseStocks()->exists() ? $variant->stock_available : '0'));
    }

    private function nextNumber(): string
    {
        $date = now('Asia/Jakarta')->format('Ymd');
        DB::table('repack_sequences')->insertOrIgnore(['date' => $date, 'last_number' => 0]);
        $number = (int) DB::table('repack_sequences')->where('date', $date)->lockForUpdate()->value('last_number') + 1;
        DB::table('repack_sequences')->where('date', $date)->update(['last_number' => $number]);

        return 'RPK-'.$date.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            $record = self::withTrashed()->lockForUpdate()->findOrFail($this->id);
            if ($record->record_status !== RecordStatus::Draft || $record->applied_at !== null) {
                throw ValidationException::withMessages(['record_status' => 'Only draft repacks can be deleted.']);
            }
            app(SystemFreezeGuard::class)->assertNotFrozen();

            $this->setRawAttributes($record->getAttributes(), true);
            $this->deletingDraft = true;
            try {
                return parent::delete();
            } finally {
                $this->deletingDraft = false;
            }
        });
    }

    public function forceDelete(): ?bool
    {
        throw ValidationException::withMessages(['record_status' => 'Repacks cannot be permanently deleted.']);
    }

    public function restore(): bool
    {
        return DB::transaction(function (): bool {
            $record = self::withTrashed()->lockForUpdate()->findOrFail($this->id);
            if (! $record->trashed() || $record->previous_record_status !== RecordStatus::Draft || $record->applied_at !== null) {
                throw ValidationException::withMessages(['record_status' => 'Only deleted drafts can be restored.']);
            }
            app(SystemFreezeGuard::class)->assertNotFrozen();
            $this->setRawAttributes($record->getAttributes(), true);
            $this->record_status = RecordStatus::Draft;
            $this->previous_record_status = null;
            $this->deleted_by = null;
            $this->deleted_at = null;
            $this->updated_by = auth()->id();

            return $this->save();
        });
    }

    /** @return list<string> */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['repack_number'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function fromProductVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    public function toProductVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }

    public function stockMutations(): MorphMany
    {
        return $this->morphMany(StockMutation::class, 'reference');
    }
}
