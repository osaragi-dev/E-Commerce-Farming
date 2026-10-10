<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Sector;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const MAP = [
        'Peternakan' => ['Ayam & Unggas'],
        'Perkebunan' => ['Sayuran', 'Buah', 'Madu'],
        'Perikanan' => ['Produk Perikanan'],
    ];

    public function run(): void
    {
        if (Category::query()->exists()) {
            return;
        }

        foreach (self::MAP as $sectorName => $categories) {
            $sector = Sector::query()->where('name', $sectorName)->first();

            if (! $sector) {
                continue;
            }

            foreach ($categories as $index => $categoryName) {
                Category::query()->create([
                    'sector_id' => $sector->id,
                    'name' => $categoryName,
                    'slug' => Str::slug($categoryName),
                    'is_featured' => false,
                    'is_active' => true,
                    'sort_order' => $index,
                ]);
            }
        }
    }
}
