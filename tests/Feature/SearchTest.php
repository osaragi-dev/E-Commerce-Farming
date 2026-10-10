<?php

use App\Livewire\Public\SearchBar;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sector;
use App\Models\Unit;
use Livewire\Livewire;

test('search bar shows matching product suggestions', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);

    $unit = Unit::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'Telur Ayam Grade A', 'is_active' => true]);
    $otherProduct = Product::factory()->create(['category_id' => $category->id, 'name' => 'Ikan Nila', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'unit_id' => $unit->id, 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $otherProduct->id, 'unit_id' => $unit->id, 'is_active' => true]);

    Livewire::test(SearchBar::class)
        ->set('query', 'telur')
        ->assertSee('Telur Ayam Grade A')
        ->assertDontSee('Ikan Nila');
});

test('search bar shows no suggestions for short query', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);

    Product::factory()->create(['category_id' => $category->id, 'name' => 'Telur Ayam', 'is_active' => true]);

    Livewire::test(SearchBar::class)
        ->set('query', 'T')
        ->assertDontSee('Telur Ayam');
});

test('catalog page filters products by search keyword', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);

    $unit = Unit::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'Telur Ayam', 'is_active' => true]);
    $otherProduct = Product::factory()->create(['category_id' => $category->id, 'name' => 'Ikan Nila', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'unit_id' => $unit->id, 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $otherProduct->id, 'unit_id' => $unit->id, 'is_active' => true]);

    $this->get(route('catalog', ['q' => 'Telur']))
        ->assertOk()
        ->assertSee('Telur Ayam')
        ->assertDontSee('Ikan Nila');
});

test('catalog search with no matches shows empty state', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);

    $this->get(route('catalog', ['q' => 'ProdukTidakAda123']))
        ->assertOk()
        ->assertSee('No products found');
});
