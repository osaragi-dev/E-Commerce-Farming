<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sector;
use App\Models\Unit;

test('catalog page renders and lists active products', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Telur Ayam Grade A',
        'is_active' => true,
    ]);

    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'price' => 25000,
        'is_active' => true,
    ]);

    $this->get(route('catalog'))
        ->assertOk()
        ->assertSee('All Products')
        ->assertSee('Buy now')
        ->assertSee('Add to cart')
        ->assertSee('Telur Ayam Grade A');
});

test('catalog filters by sector', function (): void {
    $sectorA = Sector::factory()->create(['name' => 'Peternakan', 'is_active' => true]);
    $sectorB = Sector::factory()->create(['name' => 'Perikanan', 'is_active' => true]);
    $categoryA = Category::factory()->create(['sector_id' => $sectorA->id, 'is_active' => true]);
    $categoryB = Category::factory()->create(['sector_id' => $sectorB->id, 'is_active' => true]);

    $unit = Unit::factory()->create();
    $productA = Product::factory()->create(['category_id' => $categoryA->id, 'name' => 'Telur Ayam', 'is_active' => true]);
    $productB = Product::factory()->create(['category_id' => $categoryB->id, 'name' => 'Ikan Nila', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $productA->id, 'unit_id' => $unit->id, 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $productB->id, 'unit_id' => $unit->id, 'is_active' => true]);

    $this->get(route('catalog', ['sector' => $sectorA->slug]))
        ->assertOk()
        ->assertSee('Telur Ayam')
        ->assertDontSee('Ikan Nila');
});

test('catalog hides active products without an active variant', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Produk Tanpa Varian Aktif',
        'is_active' => true,
    ]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => false]);

    $this->get(route('catalog'))
        ->assertOk()
        ->assertDontSee('Produk Tanpa Varian Aktif');
});
test('catalog sorts products by cheapest price first', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();

    $expensive = Product::factory()->create(['category_id' => $category->id, 'name' => 'Produk Mahal', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $expensive->id, 'unit_id' => $unit->id, 'price' => 50000, 'is_active' => true]);

    $cheap = Product::factory()->create(['category_id' => $category->id, 'name' => 'Produk Murah', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $cheap->id, 'unit_id' => $unit->id, 'price' => 10000, 'is_active' => true]);

    $medium = Product::factory()->create(['category_id' => $category->id, 'name' => 'Produk Sedang', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $medium->id, 'unit_id' => $unit->id, 'price' => 25000, 'is_active' => true]);

    $this->get(route('catalog', ['sort' => 'harga_termurah']))
        ->assertOk()
        ->assertSeeInOrder(['Produk Murah', 'Produk Sedang', 'Produk Mahal']);
});

test('catalog sorts products by best selling first', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();
    $bestSeller = Product::factory()->create(['category_id' => $category->id, 'name' => 'Best Seller Product', 'is_active' => true]);
    $otherProduct = Product::factory()->create(['category_id' => $category->id, 'name' => 'Other Product', 'is_active' => true]);
    $bestSellerVariant = ProductVariant::factory()->create(['product_id' => $bestSeller->id, 'unit_id' => $unit->id, 'is_active' => true]);
    $otherVariant = ProductVariant::factory()->create(['product_id' => $otherProduct->id, 'unit_id' => $unit->id, 'is_active' => true]);
    $order = Order::factory()->create();

    OrderItem::factory()->for($order)->create([
        'product_id' => $bestSeller->id,
        'product_variant_id' => $bestSellerVariant->id,
        'quantity' => 5,
    ]);
    OrderItem::factory()->for($order)->create([
        'product_id' => $otherProduct->id,
        'product_variant_id' => $otherVariant->id,
        'quantity' => 1,
    ]);

    $this->get(route('catalog', ['sort' => 'terlaris']))
        ->assertOk()
        ->assertSeeInOrder(['Best Seller Product', 'Other Product']);
});

test('catalog sorts products by most expensive price first', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();

    $expensive = Product::factory()->create(['category_id' => $category->id, 'name' => 'Produk Mahal', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $expensive->id, 'unit_id' => $unit->id, 'price' => 50000, 'is_active' => true]);

    $cheap = Product::factory()->create(['category_id' => $category->id, 'name' => 'Produk Murah', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $cheap->id, 'unit_id' => $unit->id, 'price' => 10000, 'is_active' => true]);

    $medium = Product::factory()->create(['category_id' => $category->id, 'name' => 'Produk Sedang', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $medium->id, 'unit_id' => $unit->id, 'price' => 25000, 'is_active' => true]);

    $this->get(route('catalog', ['sort' => 'harga_termahal']))
        ->assertOk()
        ->assertSeeInOrder(['Produk Mahal', 'Produk Sedang', 'Produk Murah']);
});

test('product cards keep detail links available and disable purchases only when sold out', function (int $stock, string $status): void {
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create(['stock_available' => $stock]);

    $response = $this->get(route('catalog'))->assertSee($status);

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $buttons = collect(iterator_to_array($document->getElementsByTagName('button')))
        ->filter(fn (DOMElement $button): bool => in_array($button->getAttribute('wire:click'), [
            "quickBuyNow('{$product->id}')",
            "quickAdd('{$product->id}')",
        ], true));
    $detailLinks = collect(iterator_to_array($document->getElementsByTagName('a')))
        ->filter(fn (DOMElement $link): bool => $link->getAttribute('href') === route('products.show', $product->slug));

    expect($buttons)->toHaveCount(2);
    expect($detailLinks)->toHaveCount(2);

    foreach ($buttons as $button) {
        expect($button->hasAttribute('disabled'))->toBe($stock === 0);
    }
})->with([
    'available product' => [5, 'In stock'],
    'sold out product' => [0, 'Sold out'],
]);
