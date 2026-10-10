<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RepackProductCreator
{
    /** @param array<string, mixed> $data */
    public function create(array $data, string $warehouseId): ProductVariant
    {
        Gate::authorize('create', ProductVariant::class);
        app(SystemFreezeGuard::class)->assertNotFrozen();
        $data = Validator::make($data, [
            'product_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:100'],
            'unit_id' => ['required', 'uuid'],
            'price' => ['required', 'numeric', 'min:0'],
            'price_quantity' => ['required', ...Quantity::rules()],
            'low_stock_threshold' => ['required', ...Quantity::rules(true)],
        ])->validate();

        return DB::transaction(function () use ($data, $warehouseId): ProductVariant {
            if (! Warehouse::query()->saved()->where('is_active', true)->whereKey($warehouseId)->exists()) {
                throw ValidationException::withMessages(['warehouse_id' => 'Select an active, saved location.']);
            }
            if (! Unit::query()->saved()->where('is_active', true)->whereKey($data['unit_id'])->exists()) {
                throw ValidationException::withMessages(['unit_id' => 'Select an active, saved unit.']);
            }
            $product = Product::query()->saved()->where('is_active', true)->find($data['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(['product_id' => 'Select an active, saved product.']);
            }

            return $product->variants()->create([
                'name' => $data['name'], 'unit_id' => $data['unit_id'],
                'price' => $data['price'], 'price_quantity' => $data['price_quantity'],
                'low_stock_threshold' => $data['low_stock_threshold'],
                'initial_stock' => 0, 'stock_available' => 0, 'initial_warehouse_id' => $warehouseId,
                'is_active' => true, 'record_status' => RecordStatus::Saved,
            ]);
        });
    }
}
