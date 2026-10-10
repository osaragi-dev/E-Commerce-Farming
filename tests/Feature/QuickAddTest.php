<?php

use App\Livewire\Public\Catalog;
use App\Livewire\Public\QuickAddModal;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sector;
use App\Models\Unit;
use Livewire\Livewire;

test('quick add opens popup even when product has only one variant', function (): void {
    $customer = Customer::factory()->registered()->create();

    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);
    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'is_active' => true,
        'stock_available' => 10,
    ]);

    $this->actingAs($customer, 'customer');

    Livewire::test(Catalog::class)
        ->call('quickAdd', $product->id)
        ->assertDispatched('open-quick-add-modal');

    $this->assertDatabaseCount('cart_items', 0);
});

test('quick add opens picker modal when product has multiple variants', function (): void {
    $customer = Customer::factory()->registered()->create();

    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'unit_id' => $unit->id, 'is_active' => true, 'stock_available' => 10]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'unit_id' => $unit->id, 'is_active' => true, 'stock_available' => 10]);

    $this->actingAs($customer, 'customer');

    Livewire::test(Catalog::class)
        ->call('quickAdd', $product->id)
        ->assertDispatched('open-quick-add-modal');

    $this->assertDatabaseCount('cart_items', 0);
});

test('picker modal adds selected variant to cart', function (): void {
    $customer = Customer::factory()->registered()->create();

    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);
    $variantA = ProductVariant::factory()->create(['product_id' => $product->id, 'unit_id' => $unit->id, 'is_active' => true, 'stock_available' => 10]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'unit_id' => $unit->id, 'is_active' => true, 'stock_available' => 10]);

    $this->actingAs($customer, 'customer');

    Livewire::test(QuickAddModal::class)
        ->call('open', $product->id)
        ->call('selectVariant', $variantA->id)
        ->call('confirmAdd')
        ->assertDispatched('cart-updated');

    $this->assertDatabaseHas('cart_items', [
        'product_variant_id' => $variantA->id,
        'quantity' => 1,
    ]);
});

test('guest quick add is redirected to login', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'unit_id' => $unit->id, 'is_active' => true, 'stock_available' => 10]);

    Livewire::test(Catalog::class)
        ->call('quickAdd', $product->id)
        ->assertRedirect(route('login'));
});

test('quick add keeps sold out variants visible but cannot add them', function (): void {
    $customer = Customer::factory()->registered()->create();

    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'name' => 'Grade A',
        'is_active' => true,
        'stock_available' => 0,
    ]);

    $this->actingAs($customer, 'customer');

    Livewire::test(QuickAddModal::class)
        ->call('open', $product->id)
        ->assertSee('Available stock: 0')
        ->call('selectVariant', $variant->id)
        ->call('confirmAdd');

    $this->assertDatabaseCount('cart_items', 0);
});

test('quick add asks for a valid selection when the selected variant belongs to another product', function (): void {
    $customer = Customer::factory()->registered()->create();
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create(['stock_available' => 5]);
    $otherVariant = ProductVariant::factory()->create(['stock_available' => 5]);
    $this->actingAs($customer, 'customer');

    Livewire::test(QuickAddModal::class)
        ->call('open', $product->id)
        ->set('selectedVariantId', $otherVariant->id)
        ->assertSee('Select a variant to see its price and available stock.')
        ->call('confirmAdd');

    $this->assertDatabaseCount('cart_items', 0);
});
