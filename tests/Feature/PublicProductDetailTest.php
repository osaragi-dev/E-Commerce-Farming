<?php

use App\Livewire\Public\ProductDetail;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sector;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Livewire\Livewire;

test('product detail page shows product and variants', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Telur Ayam Grade A',
        'description' => 'Fresh eggs with product information for the customer.',
        'is_active' => true,
    ]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'name' => 'Grade A',
        'price' => 25000,
        'is_active' => true,
    ]);
    WarehouseStock::factory()->for($variant, 'productVariant')->create([
        'warehouse_id' => Warehouse::factory()->create(['is_pos' => true])->id,
        'stock_available' => 5,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Telur Ayam Grade A')
        ->assertSee('Grade A')
        ->assertSee('Rp 25.000,00')
        ->assertSeeInOrder(['Select variant', 'Quantity', 'Buy now', 'About this product', 'Fresh eggs with product information for the customer.']);
});

test('product detail hides sold out variants', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'name' => 'Grade A',
        'is_active' => true,
        'stock_available' => 0,
    ]);
    $availableVariant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'name' => 'Grade B',
        'is_active' => true,
        'stock_available' => 5,
    ]);
    WarehouseStock::factory()->for($availableVariant, 'productVariant')->create([
        'warehouse_id' => Warehouse::factory()->create(['is_pos' => true])->id,
        'stock_available' => 5,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Grade B')
        ->assertDontSee('Grade A');
});

test('product detail hides stock quantities and warehouse details while preserving availability and quantity limits', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Fresh Eggs',
        'is_active' => true,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'name' => 'Grade A',
        'stock_available' => 10,
        'is_active' => true,
    ]);
    $farming = Warehouse::factory()->create(['name' => 'QA Farming Warehouse', 'is_pos' => true]);
    $sccr = Warehouse::factory()->create(['name' => 'QA SCCR Warehouse']);

    WarehouseStock::factory()->create([
        'warehouse_id' => $farming->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 4,
    ]);
    WarehouseStock::factory()->create([
        'warehouse_id' => $sccr->id,
        'product_variant_id' => $variant->id,
        'stock_available' => 6,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('In stock')
        ->assertSee('Quantity')
        ->assertDontSee('Stock available:')
        ->assertDontSee('Available stock')
        ->assertDontSee('Stock by warehouse')
        ->assertDontSee('QA Farming Warehouse')
        ->assertDontSee('QA SCCR Warehouse');

    Livewire::test(ProductDetail::class, ['product' => $product])
        ->set('quantity', 4)
        ->call('incrementQuantity')
        ->assertSet('quantity', 4);
});

test('inactive product returns 404', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'is_active' => false,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertNotFound();
});

test('product without an active variant returns 404', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'is_active' => false,
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertNotFound();
});

test('nonexistent product slug returns 404', function (): void {
    $this->get(route('products.show', 'produk-yang-tidak-pernah-ada'))
        ->assertNotFound();
});

test('product category breadcrumb opens the catalog with only that category selected', function (): void {
    $sector = Sector::factory()->create();
    $category = Category::factory()->for($sector)->create();
    $otherCategory = Category::factory()->for($sector)->create();
    $product = Product::factory()->for($category)->create(['name' => 'Fresh farm eggs']);
    $otherProduct = Product::factory()->for($otherCategory)->create(['name' => 'Garden vegetables']);
    ProductVariant::factory()->for($product)->create();
    ProductVariant::factory()->for($otherProduct)->create();
    $categoryUrl = route('catalog', ['sector' => $sector->slug, 'category' => [$category->slug]]);

    $this->get(route('products.show', $product->slug))
        ->assertSeeHtml('href="'.e($categoryUrl).'"');

    $this->get($categoryUrl)
        ->assertSee('Fresh farm eggs')
        ->assertDontSee('Garden vegetables');
});
