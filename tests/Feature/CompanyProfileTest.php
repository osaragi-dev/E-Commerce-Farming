<?php

use App\Filament\Resources\CompanyProfile\Pages\EditCompanyProfile;
use App\Filament\Resources\CompanyProfile\RelationManagers\GalleryRelationManager;
use App\Livewire\Public\AboutUs;
use App\Models\Category;
use App\Models\CompanyGallery;
use App\Models\CompanyProfile;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($actor);
});

test('admin can save a gallery description with its photo', function (): void {
    $profile = CompanyProfile::current();
    $description = "Harvest day\nFresh produce from the farm.";

    Livewire::test(GalleryRelationManager::class, [
        'ownerRecord' => $profile,
        'pageClass' => EditCompanyProfile::class,
    ])
        ->callTableAction('create', data: [
            'image' => UploadedFile::fake()->image('harvest.jpg'),
            'description' => $description,
            'sort_order' => 4,
            'is_active' => false,
        ])
        ->assertHasNoActionErrors();

    $gallery = CompanyGallery::query()->sole();

    expect($gallery->company_profile_id)->toBe($profile->id)
        ->and($gallery->description)->toBe($description)
        ->and($gallery->sort_order)->toBe(4)
        ->and($gallery->is_active)->toBeFalse()
        ->and($gallery->created_at)->not->toBeNull()
        ->and($gallery->updated_at)->toBeNull();
    Storage::disk('public')->assertExists($gallery->image);
});

test('admin can edit an existing gallery description', function (): void {
    $profile = CompanyProfile::current();
    $gallery = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'description' => 'Old description',
    ]);

    Livewire::test(GalleryRelationManager::class, [
        'ownerRecord' => $profile,
        'pageClass' => EditCompanyProfile::class,
    ])
        ->callTableAction('edit', $gallery, data: [
            'image' => UploadedFile::fake()->image('updated.jpg'),
            'description' => 'Updated description',
            'sort_order' => 8,
            'is_active' => false,
        ])
        ->assertHasNoActionErrors();

    $createdAt = $gallery->created_at;

    $gallery->refresh();

    expect($gallery->description)->toBe('Updated description')
        ->and($gallery->sort_order)->toBe(8)
        ->and($gallery->is_active)->toBeFalse()
        ->and($gallery->created_at)->toEqual($createdAt)
        ->and($gallery->updated_at)->not->toBeNull();
    Storage::disk('public')->assertExists($gallery->image);
});

test('gallery table exposes management metadata and filters by active state', function (): void {
    $profile = CompanyProfile::current();
    $active = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $inactive = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'sort_order' => 2,
        'is_active' => false,
    ]);

    $page = Livewire::test(GalleryRelationManager::class, [
        'ownerRecord' => $profile,
        'pageClass' => EditCompanyProfile::class,
    ])
        ->assertTableColumnExists('sort_order')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('updated_at')
        ->assertCanSeeTableRecords([$active, $inactive], inOrder: true)
        ->filterTable('is_active', 0);

    $page
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active]);
});

test('gallery edit modal submit action is labeled save', function (): void {
    $profile = CompanyProfile::current();
    $gallery = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
    ]);

    $page = Livewire::test(GalleryRelationManager::class, [
        'ownerRecord' => $profile,
        'pageClass' => EditCompanyProfile::class,
    ])
        ->assertTableActionHasLabel('edit', 'Edit', $gallery)
        ->mountTableAction('edit', $gallery)
        ->assertMountedActionModalSee('Save');

    $action = $page->instance()->getMountedAction();

    expect($action)->not->toBeNull()
        ->and($action->getLabel())->toBe('Edit')
        ->and($action->getModalSubmitActionLabel())->toBe('Save')
        ->and($action->getModalSubmitAction()?->getLabel())->toBe('Save');
});

test('gallery edit form stacks photo and description vertically', function (): void {
    $profile = CompanyProfile::current();
    $gallery = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
    ]);

    $page = Livewire::test(GalleryRelationManager::class, [
        'ownerRecord' => $profile,
        'pageClass' => EditCompanyProfile::class,
    ])->mountTableAction('edit', $gallery);

    $schema = $page->instance()->getSchema('mountedActionSchema0');

    expect($schema)->not->toBeNull()
        ->and($schema?->getColumns('lg'))->toBe(1)
        ->and($schema?->getComponent('image')?->getColumnSpan('default'))->toBe(1)
        ->and($schema?->getComponent('description')?->getColumnSpan('default'))->toBe(1);
});

test('new gallery form stacks photo and description vertically', function (): void {
    $page = Livewire::test(GalleryRelationManager::class, [
        'ownerRecord' => CompanyProfile::current(),
        'pageClass' => EditCompanyProfile::class,
    ])->mountTableAction('create');

    $schema = $page->instance()->getSchema('mountedActionSchema0');

    expect($schema)->not->toBeNull()
        ->and($schema?->getColumns('lg'))->toBe(1)
        ->and($schema?->getComponent('image')?->getColumnSpan('default'))->toBe(1)
        ->and($schema?->getComponent('description')?->getColumnSpan('default'))->toBe(1);
});

test('company profile sections stack vertically', function (): void {
    $schema = Livewire::test(EditCompanyProfile::class, [
        'record' => CompanyProfile::current()->getRouteKey(),
    ])->instance()->getSchema('form');

    expect($schema->getColumns('lg'))->toBe(1);
});

test('about us does not render sectors or sector products', function (): void {
    $sector = Sector::factory()->create(['name' => 'Hidden About Sector']);
    $category = Category::factory()->create(['sector_id' => $sector->id]);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Hidden About Product',
    ]);
    $profile = CompanyProfile::current();
    $profile->update(['email' => 'about@example.test']);
    $gallery = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'image' => 'company-profile/about.jpg',
    ]);
    Storage::disk('public')->put($gallery->image, UploadedFile::fake()->image('about.jpg')->get());

    Livewire::test(AboutUs::class)
        ->assertSee('About Us')
        ->assertSee($profile->company_name)
        ->assertSee('Contact & Location')
        ->assertSee('about@example.test')
        ->assertSee($gallery->imageUrl())
        ->assertDontSee('Our Sectors')
        ->assertDontSee($sector->name)
        ->assertDontSee($product->name)
        ->assertDontSeeHtml('x-on:sector-overview-ready.window');
});

test('about us ignores legacy sector query parameters', function (): void {
    $sector = Sector::factory()->create(['name' => 'Legacy About Sector']);
    $category = Category::factory()->create(['sector_id' => $sector->id]);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'Legacy About Product',
    ]);

    $this->get(route('company-profile.show', ['sector' => $sector->slug]))
        ->assertSuccessful()
        ->assertDontSee('Our Sectors')
        ->assertDontSee($product->name)
        ->assertDontSeeHtml('x-on:sector-overview-ready.window');
});

test('about us opens gallery photos and shows descriptions as plain text', function (): void {
    $profile = CompanyProfile::current();
    $gallery = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'description' => "Gallery <strong>caption</strong>\nSecond line",
    ]);
    Storage::disk('public')->put($gallery->image, UploadedFile::fake()->image('gallery.jpg')->get());

    Livewire::test(AboutUs::class)
        ->assertSeeHtml('id="about-gallery-title"')
        ->assertSee($gallery->imageUrl())
        ->assertDontSee('Life at Agung Farm')
        ->assertDontSeeHtml('src="https://www.instagram.com/reel/')
        ->assertDontSee('Second line')
        ->call('openGallery', $gallery->id)
        ->assertSet('selectedGalleryId', $gallery->id)
        ->assertSeeHtml('id="company-gallery-modal-title"')
        ->assertSee('Gallery &lt;strong&gt;caption&lt;/strong&gt;', false)
        ->assertSee('Second line')
        ->assertDontSeeHtml('<strong>caption</strong>')
        ->call('closeGallery')
        ->assertSet('selectedGalleryId', null)
        ->assertDontSee('Second line');
});

test('about us shows active gallery photos in sort order without a hero image', function (): void {
    $profile = CompanyProfile::current();
    $first = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'image' => 'company-profile/first.jpg',
        'sort_order' => 10,
        'is_active' => true,
    ]);
    $second = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'image' => 'company-profile/second.jpg',
        'sort_order' => 20,
        'is_active' => true,
    ]);
    $inactive = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'image' => 'company-profile/inactive.jpg',
        'sort_order' => 0,
        'is_active' => false,
    ]);
    Storage::disk('public')->put($first->image, UploadedFile::fake()->image('first.jpg')->get());
    Storage::disk('public')->put($second->image, UploadedFile::fake()->image('second.jpg')->get());
    Storage::disk('public')->put($inactive->image, UploadedFile::fake()->image('inactive.jpg')->get());

    Livewire::test(AboutUs::class)
        ->assertSee($first->imageUrl())
        ->assertSee($second->imageUrl())
        ->assertSeeInOrder([
            'wire:key="company-gallery-'.$first->id.'"',
            'wire:key="company-gallery-'.$second->id.'"',
        ], false)
        ->assertDontSeeHtml('class="about-feature-photo"')
        ->assertDontSeeHtml('class="about-logo"')
        ->assertDontSee($inactive->imageUrl());
});

test('about us only opens active gallery photos from its profile', function (): void {
    $profile = CompanyProfile::current();
    $active = CompanyGallery::factory()->create(['company_profile_id' => $profile->id]);
    $inactive = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'is_active' => false,
        'description' => 'Private photo description',
    ]);

    Livewire::test(AboutUs::class)
        ->call('openGallery', $active->id)
        ->assertSet('selectedGalleryId', $active->id)
        ->call('openGallery', $inactive->id)
        ->assertSet('selectedGalleryId', null)
        ->assertDontSee('Private photo description')
        ->call('openGallery', 'invalid-id')
        ->assertSet('selectedGalleryId', null)
        ->set('selectedGalleryId', $inactive->id)
        ->assertDontSeeHtml('id="company-gallery-modal-title"');
});

test('missing gallery files render placeholders without broken image requests', function (): void {
    $profile = CompanyProfile::current();
    $gallery = CompanyGallery::factory()->create([
        'company_profile_id' => $profile->id,
        'image' => 'company-profile/missing.jpg',
        'description' => 'The herb garden after harvest.',
    ]);

    Livewire::test(AboutUs::class)
        ->assertSeeHtml('aria-label="'.e($profile->company_name).' gallery photo 1: photo unavailable"')
        ->assertDontSeeHtml('src="'.$gallery->imageUrl().'"')
        ->call('openGallery', $gallery->id)
        ->assertSeeHtml('aria-label="'.e($profile->company_name).' gallery photo: photo unavailable"')
        ->assertDontSeeHtml('src="'.$gallery->imageUrl().'"');
});

test('about us hides the gallery when its only photo is inactive', function (): void {
    $gallery = CompanyGallery::factory()->create([
        'company_profile_id' => CompanyProfile::current()->id,
        'is_active' => false,
    ]);

    Livewire::test(AboutUs::class)
        ->assertDontSeeHtml('id="about-gallery-title"')
        ->assertDontSee('Life at Agung Farm')
        ->assertDontSee($gallery->imageUrl());
});

test('about us renders contact channels as clickable links', function (): void {
    CompanyProfile::current()->update([
        'email' => 'contact@example.test',
        'whatsapp_number' => '0812 3456 7890',
        'instagram' => '@agungfarm',
    ]);

    Livewire::test(AboutUs::class)
        ->assertSeeHtml('href="mailto:contact@example.test"')
        ->assertSeeHtml('href="https://wa.me/6281234567890"')
        ->assertSeeHtml('href="https://instagram.com/agungfarm"');
});

test('about us embeds the encoded address and keeps a directions link', function (): void {
    CompanyProfile::current()->update([
        'company_name' => 'Agung Farm',
        'address' => 'W985+P3X, Semarang & Garden',
        'email' => null,
        'whatsapp_number' => null,
        'instagram' => null,
    ]);

    Livewire::test(AboutUs::class)
        ->assertSeeHtml('src="https://www.google.com/maps?q=W985%2BP3X%2C%20Semarang%20%26%20Garden&amp;output=embed"')
        ->assertSeeHtml('title="Agung Farm location on Google Maps"')
        ->assertSeeHtml('href="https://www.google.com/maps/search/?api=1&amp;query=W985%2BP3X%2C+Semarang+%26+Garden"')
        ->assertSee('Get directions')
        ->assertDontSee('Chat on WhatsApp')
        ->assertDontSee('Write to us')
        ->assertDontSee('Follow on Instagram');
});

test('about us keeps contact available without an address or an empty map', function (): void {
    CompanyProfile::current()->update([
        'address' => null,
        'email' => 'contact@example.test',
        'whatsapp_number' => null,
        'instagram' => null,
    ]);

    Livewire::test(AboutUs::class)
        ->assertSeeHtml('href="mailto:contact@example.test"')
        ->assertDontSeeHtml('src="https://www.google.com/maps')
        ->assertDontSee('Get directions')
        ->assertDontSee('Chat on WhatsApp')
        ->assertDontSee('Follow on Instagram');
});

test('about us omits the contact section when all contact fields are missing', function (): void {
    CompanyProfile::current()->update([
        'address' => null,
        'email' => null,
        'whatsapp_number' => null,
        'instagram' => null,
    ]);

    Livewire::test(AboutUs::class)
        ->assertDontSeeHtml('id="contact"')
        ->assertDontSeeHtml('src="https://www.google.com/maps');
});

test('about us shows the profile logo on the left without gallery photos or reels', function (): void {
    $profile = CompanyProfile::current();
    $profile->update(['logo' => 'company-profile/logo.jpg']);
    Storage::disk('public')->put($profile->logo, UploadedFile::fake()->image('logo.jpg')->get());

    $this->get(route('company-profile.show'))
        ->assertSuccessful()
        ->assertSee('About Us')
        ->assertSeeInOrder([$profile->logoUrl(), $profile->company_name], false)
        ->assertDontSeeHtml('id="about-gallery-title"')
        ->assertDontSee('Life at Agung Farm');
});

test('about us keeps a single-column intro when no logo is uploaded', function (): void {
    Livewire::test(AboutUs::class)
        ->assertSee('Explore Our Products')
        ->assertDontSeeHtml('alt="'.e(CompanyProfile::current()->company_name).' logo"')
        ->assertDontSee('md:grid-cols-[minmax(180px,260px)_minmax(0,1fr)]');
});

test('about us shows a placeholder when the profile logo file is missing', function (): void {
    $profile = CompanyProfile::current();
    $profile->update(['logo' => 'company-profile/missing-logo.jpg']);

    Livewire::test(AboutUs::class)
        ->assertSeeHtml('aria-label="'.e($profile->company_name).' logo: photo unavailable"')
        ->assertDontSeeHtml('src="'.$profile->logoUrl().'"');
});

test('instagram reels remain on the homepage only', function (): void {
    $about = $this->get(route('company-profile.show'))->getContent();
    $home = $this->get(route('home'))->getContent();

    preg_match_all('~https://www\.instagram\.com/reel/[^/]+/embed/~', $about, $aboutReels);
    preg_match_all('~https://www\.instagram\.com/reel/[^/]+/embed/~', $home, $homeReels);

    expect($aboutReels[0])->toBeEmpty()
        ->and($homeReels[0])->toHaveCount(5);
});
