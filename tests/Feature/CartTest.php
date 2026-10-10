<?php

use App\Enums\RecordStatus;
use App\Livewire\Public\Cart as CartLivewire;
use App\Livewire\Public\ProductDetail;
use App\Livewire\Public\QuickAddModal;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Models\Sector;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('customer can change a cart variant with current pricing and retained selection', function (): void {
    $customer = Customer::factory()->registered()->create();
    [$product, $variant] = makeProductWithVariant();
    $target = ProductVariant::factory()->for($product)->create(['name' => 'Large box', 'stock_available' => 10, 'price' => 50000]);
    ProductVariantPrice::factory()->for($target)->create(['price' => 40000]);
    $item = Cart::forCustomer($customer)->addItem($variant, 2);
    $this->actingAs($customer, 'customer');

    Livewire::test(CartLivewire::class)
        ->call('changeVariant', $item->id, $target->id)
        ->assertHasNoErrors()
        ->assertSet('selectedItemIds', [$item->id])
        ->assertSet('subtotal', 80000.0)
        ->assertSee('Large box')
        ->assertSee($target->unit->name)
        ->assertDispatched('cart-updated');

    expect($item->fresh()->product_variant_id)->toBe($target->id);
    expect($item->fresh()->quantity)->toBe('2.000');
});

test('changing to a variant already in the cart merges quantities and selection', function (array $selected, bool $mergedSelected): void {
    $customer = Customer::factory()->registered()->create();
    [$product, $variant] = makeProductWithVariant();
    $target = ProductVariant::factory()->for($product)->create(['stock_available' => 10, 'price' => 40000]);
    $cart = Cart::forCustomer($customer);
    $item = $cart->addItem($variant, 2);
    $existing = $cart->addItem($target, 3);
    $ids = ['source' => $item->id, 'target' => $existing->id];
    $this->actingAs($customer, 'customer');

    Livewire::test(CartLivewire::class)
        ->set('selectedItemIds', array_map(fn (string $key): string => $ids[$key], $selected))
        ->call('changeVariant', $item->id, $target->id)
        ->assertHasNoErrors()
        ->assertSet('selectedItemIds', $mergedSelected ? [$existing->id] : [])
        ->assertSet('subtotal', $mergedSelected ? 200000.0 : 0.0);

    expect($existing->fresh()->quantity)->toBe('5.000');
    $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    $this->assertDatabaseCount('cart_items', 1);
})->with([
    'source selected' => [['source'], true],
    'target selected' => [['target'], true],
    'both selected' => [['source', 'target'], true],
    'neither selected' => [[], false],
]);

test('cart variant change rejects unavailable or unrelated variants without mutation', function (string $scenario): void {
    $customer = Customer::factory()->registered()->create();
    [$product, $variant] = makeProductWithVariant();
    $target = ProductVariant::factory()->for($product)->create(['stock_available' => 10]);
    $cart = Cart::forCustomer($customer);
    $item = $cart->addItem($variant, 2);
    $targetId = $target->id;

    match ($scenario) {
        'foreign product' => $target->update(['product_id' => Product::factory()->create()->id]),
        'inactive variant' => $target->update(['is_active' => false]),
        'inactive product' => $product->update(['is_active' => false]),
        'deleted variant' => $target->delete(),
        'draft variant' => $target->update(['record_status' => RecordStatus::Draft]),
        'nonexistent variant' => $targetId = (string) Str::uuid(),
        'malformed variant' => $targetId = 'invalid',
        'insufficient stock' => $target->update(['stock_available' => 1]),
        'merged insufficient stock' => $cart->addItem($target, 9),
    };
    $this->actingAs($customer, 'customer');

    Livewire::test(CartLivewire::class)
        ->call('changeVariant', $item->id, $targetId)
        ->assertHasErrors('variant.'.$item->id)
        ->assertNotDispatched('cart-updated');

    expect($item->fresh()->product_variant_id)->toBe($variant->id);
    expect($item->fresh()->quantity)->toBe('2.000');
    if ($scenario === 'merged insufficient stock') {
        expect($cart->items()->where('product_variant_id', $target->id)->first()->quantity)->toBe('9.000');
    }
})->with(['foreign product', 'inactive variant', 'inactive product', 'deleted variant', 'draft variant', 'nonexistent variant', 'malformed variant', 'insufficient stock', 'merged insufficient stock']);

test('customer cannot change another customers cart variant', function (): void {
    $customer = Customer::factory()->registered()->create();
    [$product, $variant] = makeProductWithVariant();
    $target = ProductVariant::factory()->for($product)->create(['stock_available' => 10]);
    $item = Cart::forCustomer(Customer::factory()->registered()->create())->addItem($variant, 2);
    $this->actingAs($customer, 'customer');

    Livewire::test(CartLivewire::class)
        ->call('changeVariant', $item->id, $target->id)
        ->assertHasErrors('variant.'.$item->id)
        ->assertNotDispatched('cart-updated');

    expect($item->fresh()->product_variant_id)->toBe($variant->id);
    expect($item->fresh()->quantity)->toBe('2.000');
});

function makeProductWithVariant(int $stock = 10, int $price = 25000): array
{
    Warehouse::query()->where('code', 'WH-SCCR')->update(['is_pos' => true]);
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'is_active' => true,
    ]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'price' => $price,
        'is_active' => true,
        'stock_available' => $stock,
    ]);

    return [$product, $variant];
}

test('guest is redirected to login when adding to cart', function (): void {
    [$product, $variant] = makeProductWithVariant();

    Livewire::test(ProductDetail::class, ['product' => $product])
        ->call('addToCart')
        ->assertRedirect(route('login'));
});

test('customer can add product to cart', function (): void {
    $customer = Customer::factory()->registered()->create();

    [$product, $variant] = makeProductWithVariant(stock: 10);

    $this->actingAs($customer, 'customer');

    Livewire::test(ProductDetail::class, ['product' => $product])
        ->set('quantity', 2)
        ->call('openAddToCart')
        ->assertDispatched('open-quick-add-modal', productId: $product->id, variantId: $variant->id, quantity: 2);

    Livewire::test(QuickAddModal::class)
        ->call('open', $product->id, false, $variant->id, 2)
        ->call('confirmAdd');

    $this->assertDatabaseHas('cart_items', [
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);
});

test('product detail add to cart opens the picker with the selected variant and quantity', function (): void {
    $customer = Customer::factory()->registered()->create();

    [$product, $variant] = makeProductWithVariant(stock: 10);

    $this->actingAs($customer, 'customer');

    Livewire::test(ProductDetail::class, ['product' => $product])
        ->set('selectedVariantId', $variant->id)
        ->set('quantity', 3)
        ->call('openAddToCart')
        ->assertDispatched('open-quick-add-modal', productId: $product->id, variantId: $variant->id, quantity: 3);

    $this->assertDatabaseCount('cart_items', 0);
});

test('buy now confirmation shows available stock', function (): void {
    $customer = Customer::factory()->registered()->create();

    [$product] = makeProductWithVariant(stock: 7);

    $this->actingAs($customer, 'customer');

    Livewire::test(ProductDetail::class, ['product' => $product])
        ->call('buyNow')
        ->assertSet('showBuyNowConfirm', true)
        ->assertSee('Available stock');
});

test('adding the same variant twice merges quantity instead of duplicating', function (): void {
    $customer = Customer::factory()->registered()->create();

    [$product, $variant] = makeProductWithVariant(stock: 10);

    $this->actingAs($customer, 'customer');

    $component = Livewire::test(ProductDetail::class, ['product' => $product]);
    $component->set('quantity', 2)->call('addToCart');
    $component->set('quantity', 3)->call('addToCart');

    $this->assertDatabaseHas('cart_items', [
        'product_variant_id' => $variant->id,
        'quantity' => 5,
    ]);
    $this->assertDatabaseCount('cart_items', 1);
});

test('cannot add more than available stock across multiple add-to-cart calls', function (): void {
    $customer = Customer::factory()->registered()->create();

    [$product, $variant] = makeProductWithVariant(stock: 5);

    $this->actingAs($customer, 'customer');

    $component = Livewire::test(ProductDetail::class, ['product' => $product]);
    $component->set('quantity', 3)->call('addToCart');
    $component->set('quantity', 3)->call('addToCart')
        ->assertHasErrors(['quantity']);

    $this->assertDatabaseHas('cart_items', [
        'product_variant_id' => $variant->id,
        'quantity' => 3,
    ]);
});

test('customer can update quantity and remove item in cart page', function (): void {
    $customer = Customer::factory()->registered()->create();

    [$product, $variant] = makeProductWithVariant(stock: 10);

    $this->actingAs($customer, 'customer');
    $cart = Cart::forCustomer($customer);
    $item = $cart->addItem($variant, 1);

    Livewire::test(CartLivewire::class)
        ->call('incrementQuantity', $item->id)
        ->assertOk();

    $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 2]);

    Livewire::test(CartLivewire::class)
        ->call('removeItem', $item->id);

    $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
});

test('guest cannot access cart page', function (): void {
    $this->get(route('cart'))->assertRedirect(route('login'));
});
