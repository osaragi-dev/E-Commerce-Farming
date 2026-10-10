<?php

use App\Models\ProductVariant;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SectorSeeder;
use Database\Seeders\UnitSeeder;

test('product seeder gives every variant an initial stock of 100', function (): void {
    $this->seed(UnitSeeder::class);
    $this->seed(SectorSeeder::class);
    $this->seed(CategorySeeder::class);
    $this->seed(ProductSeeder::class);

    expect(ProductVariant::query()->exists())->toBeTrue()
        ->and(ProductVariant::query()->where('initial_stock', '!=', 100)->exists())->toBeFalse();
});
