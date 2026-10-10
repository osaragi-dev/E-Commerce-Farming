<?php

use App\Livewire\Public\Home;
use App\Models\Category;
use App\Models\CompanyGallery;
use App\Models\CompanyProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDisplaySetting;
use App\Models\ProductVariant;
use App\Models\Sector;
use App\Models\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('best seller filters leave newest products unchanged', function (): void {
    $garden = Sector::factory()->create(['name' => 'Garden', 'is_active' => true]);
    $fishery = Sector::factory()->create(['name' => 'Fishery', 'is_active' => true]);
    $gardenCategory = Category::factory()->create(['sector_id' => $garden->id, 'is_active' => true]);
    $fisheryCategory = Category::factory()->create(['sector_id' => $fishery->id, 'is_active' => true]);
    $lettuce = Product::factory()->create(['category_id' => $gardenCategory->id, 'name' => 'Fresh Lettuce', 'is_active' => true]);
    $fish = Product::factory()->create(['category_id' => $fisheryCategory->id, 'name' => 'Fresh Tilapia', 'is_active' => true]);
    $lettuceVariant = ProductVariant::factory()->create(['product_id' => $lettuce->id, 'is_active' => true]);
    $fishVariant = ProductVariant::factory()->create(['product_id' => $fish->id, 'is_active' => true]);
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create(['product_id' => $lettuce->id, 'product_variant_id' => $lettuceVariant->id, 'quantity' => 4]);
    OrderItem::factory()->for($order)->create(['product_id' => $fish->id, 'product_variant_id' => $fishVariant->id, 'quantity' => 2]);

    Livewire::test(Home::class)
        ->set('productSector', $garden->slug)
        ->assertViewHas('bestSellerProducts', fn ($products) => $products->modelKeys() === [$lettuce->id])
        ->assertViewHas('newestProducts', fn ($products) => $products->contains($lettuce) && $products->contains($fish))
        ->set('productSearch', 'TILAPIA')
        ->assertViewHas('bestSellerProducts', fn ($products) => $products->isEmpty())
        ->assertSee('No products found')
        ->set('productSector', '')
        ->assertViewHas('bestSellerProducts', fn ($products) => $products->modelKeys() === [$fish->id])
        ->assertViewHas('newestProducts', fn ($products) => $products->count() === 2)
        ->call('resetProductFilters')
        ->assertViewHas('bestSellerProducts', fn ($products) => $products->modelKeys() === [$lettuce->id, $fish->id]);
});

test('company page shows active gallery photos without a hero image or reels', function (): void {
    Storage::fake('public');
    $first = CompanyGallery::factory()->create(['description' => 'Our herb garden', 'sort_order' => 1]);
    $second = CompanyGallery::factory()->create(['description' => 'Our fish pond', 'sort_order' => 2]);
    $inactive = CompanyGallery::factory()->create(['description' => 'Hidden farm photo', 'sort_order' => 0, 'is_active' => false]);
    Storage::disk('public')->put($first->image, UploadedFile::fake()->image('garden.jpg')->get());
    Storage::disk('public')->put($second->image, UploadedFile::fake()->image('pond.jpg')->get());

    $this->get(route('company-profile.show'))
        ->assertSee($first->imageUrl(), false)
        ->assertSee($second->imageUrl(), false)
        ->assertSeeHtml('id="about-gallery-title"')
        ->assertDontSeeHtml('class="about-feature-photo"')
        ->assertDontSee('Life at Agung Farm')
        ->assertDontSee($inactive->imageUrl(), false)
        ->assertDontSee('Hidden farm photo');
});

test('homepage uses reels instead of gallery photos and replaces missing sector files', function (): void {
    Storage::fake('public');
    $gallery = CompanyGallery::factory()->create(['image' => 'company-profile/missing.jpg', 'description' => 'Our herb garden']);
    $sector = Sector::factory()->create(['name' => 'Garden', 'image' => 'sectors/missing.jpg']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('src="https://www.instagram.com/reel/Ddyd1XkB5OG/embed/"')
        ->assertSeeHtml('src="https://www.instagram.com/reel/DbA68Q5BbNu/embed/"')
        ->assertSeeHtml('aria-label="Garden: photo unavailable"')
        ->assertDontSeeHtml('src="'.$gallery->imageUrl().'"')
        ->assertDontSeeHtml('src="'.Storage::disk('public')->url($sector->image).'"');
});

test('homepage renders without data', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Newest Products')
        ->assertSee('Best Sellers')
        ->assertSee('No active sectors yet')
        ->assertDontSee('halo@farming.test');
});

test('homepage lists active sectors and their newest products', function (): void {
    $sector = Sector::factory()->create(['name' => 'Peternakan', 'is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create(['name' => 'box']);

    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Telur Ayam Grade A',
        'is_active' => true,
        'is_featured' => true,
    ]);

    ProductVariant::factory()->create([
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'price' => 25000,
        'is_active' => true,
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Peternakan')
        ->assertSee('Telur Ayam Grade A')
        ->assertSee('Rp 25.000,00');
});

test('homepage hides products from inactive categories', function (): void {
    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => false]);

    Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Produk Tersembunyi',
        'is_active' => true,
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Produk Tersembunyi');
});

test('homepage uses the configured newest product period', function (): void {
    $this->travelTo('2026-09-10 12:00:00');

    ProductDisplaySetting::current()->update(['new_badge_days' => 7]);

    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();

    $oldProduct = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Old Product',
        'is_active' => true,
        'created_at' => now()->subDays(8),
        'activated_at' => now()->subDays(8),
    ]);

    $recentProduct = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Recent Product',
        'is_active' => true,
        'created_at' => now()->subDays(3),
        'activated_at' => now()->subDays(3),
    ]);

    ProductVariant::factory()->create(['product_id' => $oldProduct->id, 'unit_id' => $unit->id, 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $recentProduct->id, 'unit_id' => $unit->id, 'is_active' => true]);

    $this->get(route('home'))
        ->assertSee('Recent Product')
        ->assertDontSee('Old Product');
});

test('homepage shows an old product after it is activated again', function (): void {
    $this->travelTo('2026-09-10 12:00:00');

    $sector = Sector::factory()->create(['is_active' => true]);
    $category = Category::factory()->create(['sector_id' => $sector->id, 'is_active' => true]);
    $unit = Unit::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Reactivated Product',
        'is_active' => false,
        'created_at' => now()->subMonths(2),
        'activated_at' => null,
    ]);

    ProductVariant::factory()->create(['product_id' => $product->id, 'unit_id' => $unit->id, 'is_active' => true]);

    $product->update(['is_active' => true]);

    expect($product->fresh()->activated_at)->toEqual(now());

    $this->get(route('home'))
        ->assertSee('Reactivated Product');
});

test('footer links customers to the help page', function (): void {
    $this->get(route('home'))
        ->assertSee(route('help'), false)
        ->assertSee('Help');

    $this->get(route('help'))
        ->assertOk()
        ->assertSee('How can we help?')
        ->assertSee('Delivery &amp; Pickup', false)
        ->assertSee('Payment Methods');
});

test('homepage hides the fallback footer email when none is configured', function (): void {
    CompanyProfile::current()->update(['email' => null]);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('halo@farming.test')
        ->assertDontSee('mailto:halo@farming.test', false);
});

test('homepage shows the configured company email in the footer', function (): void {
    CompanyProfile::current()->update(['email' => 'contact@example.com']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('contact@example.com')
        ->assertSee('mailto:contact@example.com', false);
});

test('homepage footer links configured contact details to their external destinations', function (): void {
    $profile = CompanyProfile::current();
    $profile->update([
        'address' => 'Jl. Kebun Raya No. 10, Semarang',
        'whatsapp_number' => '081234567890',
        'instagram' => '@farming.official',
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('href="'.e($profile->mapsUrl()).'" target="_blank" rel="noopener noreferrer"')
        ->assertSeeHtml('href="'.e($profile->whatsappUrl()).'" target="_blank" rel="noopener noreferrer"')
        ->assertSeeHtml('href="'.e($profile->instagramUrl()).'" target="_blank" rel="noopener noreferrer"')
        ->assertSee('WhatsApp: 081234567890')
        ->assertSeeHtml('&commat;farming.official');
});

test('homepage footer keeps the fallback address as plain text when none is configured', function (): void {
    CompanyProfile::current()->update(['address' => null]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Gunungpati, Semarang, Jawa Tengah')
        ->assertDontSee('href=""', false)
        ->assertDontSee('google.com/maps/search/', false);
});
