<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Sesuai Bab 8 Blueprint (struktur Sector -> Category -> Product ->
     * Variant), tapi nama produk & satuan sudah dikonfirmasi ulang oleh
     * supervisor SCCR (menggantikan Telur/Madu Murni/dst di draft awal).
     * Ikan Nila: satuan belum dikonfirmasi -> sementara pakai 'pack',
     * TODO update begitu supervisor konfirmasi.
     *
     * @var array<string, list<array{name: string, unit: string, variants: list<string>}>>
     */
    private const CATALOG = [
        'Ayam & Unggas' => [
            ['name' => 'Telur Herbal', 'unit' => 'box', 'variants' => ['Grade A', 'Grade B']],
            ['name' => 'Ayam', 'unit' => 'ekor', 'variants' => ['Size A (800-900gr)', 'Size B (900-1.200gr)']],
        ],
        'Sayuran' => [
            ['name' => 'Selada', 'unit' => 'pack', 'variants' => ['Standar']],
            ['name' => 'Brokoli', 'unit' => 'pack', 'variants' => ['Standar']],
            ['name' => 'Cabai Rawit', 'unit' => 'pack', 'variants' => ['Standar']],
        ],
        'Madu' => [
            ['name' => 'Madu Trigocel', 'unit' => 'botol', 'variants' => ['250ml']],
        ],
        'Produk Perikanan' => [
            // TODO: satuan Ikan Nila belum dikonfirmasi, sementara 'pack'.
            ['name' => 'Ikan Nila', 'unit' => 'pack', 'variants' => ['Standar']],
        ],
    ];

    public function run(): void
    {
        if (Product::query()->exists()) {
            return;
        }

        foreach (self::CATALOG as $categoryName => $products) {
            $category = Category::query()->where('name', $categoryName)->first();

            if (! $category) {
                continue;
            }

            foreach ($products as $productData) {
                $unit = Unit::query()->where('code', $productData['unit'])->first();

                if (! $unit) {
                    continue;
                }

                $product = Product::query()->create([
                    'category_id' => $category->id,
                    'name' => $productData['name'],
                    'slug' => Str::slug($productData['name']),
                    'is_active' => true,
                ]);

                foreach ($productData['variants'] as $variantName) {
                    ProductVariant::query()->create([
                        'product_id' => $product->id,
                        'unit_id' => $unit->id,
                        'name' => $variantName,
                        'price' => fake()->randomFloat(2, 2_500, 45_000),
                        'initial_stock' => 100,
                        'low_stock_threshold' => 10,
                        'is_active' => true,
                    ]);
                }
            }
        }
    }
}
