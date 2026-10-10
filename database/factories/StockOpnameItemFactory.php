<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockOpnameItem;
use App\Models\StockOpnameSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockOpnameItem> */
class StockOpnameItemFactory extends Factory
{
    protected $model = StockOpnameItem::class;

    public function definition(): array
    {
        $systemQty = fake()->numberBetween(0, 100);
        $physicalQty = fake()->numberBetween(0, 100);

        return [
            'stock_opname_session_id' => StockOpnameSession::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'system_qty' => $systemQty,
            'physical_qty' => $physicalQty,
            'variance_qty' => $physicalQty - $systemQty,
            'unit' => 'gram',
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
