<?php

use App\Livewire\Public\MyAccount;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('guest cannot access my account', function (): void {
    $this->get(route('account'))->assertRedirect(route('login'));
});

test('users no longer store gender data', function (): void {
    $user = new User;

    expect(Schema::hasColumn('users', 'gender'))->toBeFalse()
        ->and($user->getFillable())->not->toContain('gender')
        ->and($user->getCasts())->not->toHaveKey('gender');
});

test('customer can see the profile menu and my account fields', function (): void {
    $customer = Customer::factory()->registered()->create(['address' => 'SCCR']);

    $this->actingAs($customer, 'customer')
        ->get(route('account'))
        ->assertOk()
        ->assertSee('Profile')
        ->assertSee('My Account')
        ->assertSee('My Orders')
        ->assertSee('Profile Photo')
        ->assertSee('Edit')
        ->assertSeeHtml('class="account-edit-button"')
        ->assertSeeHtml('aria-label="Edit profile"')
        ->assertSeeHtml('class="account-edit-icon"')
        ->assertSeeHtml('class="profile-toast-notification"')
        ->assertSeeHtml('class="profile-toast-icon"')
        ->assertSeeHtml('class="profile-toast-dismiss"')
        ->assertSeeHtml('role="alert"')
        ->assertSee('profile-updated')
        ->assertSee('x-on:notify.window')
        ->assertDontSee('profile-success')
        ->assertSee('Phone Number')
        ->assertDontSee('Save Changes')
        ->assertDontSee('Gender')
        ->assertSee('SCCR');
});

test('customer can open and cancel profile edit mode', function (): void {
    $customer = Customer::factory()->registered()->create([
        'name' => 'Original Name',
        'phone' => '08123456789',
        'address' => 'SCCR',
    ]);

    $this->actingAs($customer, 'customer');

    Livewire::test(MyAccount::class)
        ->assertSet('isEditing', false)
        ->call('startEditing')
        ->assertSet('isEditing', true)
        ->assertSee('Save Changes')
        ->assertSee('Cancel')
        ->set('name', 'Changed Name')
        ->call('cancelEdit')
        ->assertSet('isEditing', false)
        ->assertSet('name', 'Original Name')
        ->assertSee('Edit');
});

test('customer can update their profile data', function (): void {
    Storage::fake('public');

    $customer = Customer::factory()->registered()->create([
        'phone' => '08123456789',
        'address' => 'SCCR',
    ]);

    $this->actingAs($customer, 'customer');

    Livewire::test(MyAccount::class)
        ->call('startEditing')
        ->set('name', 'Budi Santoso')
        ->set('phone', '081298765432')
        ->set('email', 'budi.profile@example.com')
        ->set('address', 'Agung Farm & Garden')
        ->set('profilePhoto', UploadedFile::fake()->image('avatar.png'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('isEditing', false)
        ->assertDispatched('profile-updated', message: 'Profile updated successfully.');

    $customer->refresh();

    expect($customer->name)->toBe('Budi Santoso')
        ->and($customer->phone)->toBe('081298765432')
        ->and($customer->email)->toBe('budi.profile@example.com')
        ->and($customer->address)->toBe('Agung Farm & Garden')
        ->and($customer->profile_photo_path)->toStartWith('profile-photos/');

    Storage::disk('public')->assertExists($customer->profile_photo_path);
});

test('customer can cancel profile changes without saving them', function (): void {
    $customer = Customer::factory()->registered()->create([
        'name' => 'Original Name',
        'phone' => '08123456789',
        'address' => 'Lobby SCCR',
    ]);

    $this->actingAs($customer, 'customer');

    Livewire::test(MyAccount::class)
        ->call('startEditing')
        ->set('name', 'Changed Name')
        ->set('phone', '081298765432')
        ->set('address', 'Agung Farm & Garden')
        ->call('cancelEdit')
        ->assertSet('name', 'Original Name')
        ->assertSet('phone', '08123456789')
        ->assertSet('address', 'Lobby SCCR')
        ->assertHasNoErrors();

    expect($customer->refresh()->name)->toBe('Original Name')
        ->and($customer->phone)->toBe('08123456789')
        ->and($customer->address)->toBe('Lobby SCCR');
});

test('profile requires name phone email and address', function (): void {
    $customer = Customer::factory()->registered()->create();

    $this->actingAs($customer, 'customer');

    Livewire::test(MyAccount::class)
        ->call('startEditing')
        ->set('name', '')
        ->set('phone', '')
        ->set('email', '')
        ->set('address', '')
        ->call('save')
        ->assertHasErrors(['name', 'phone', 'email', 'address'])
        ->assertNotDispatched('profile-updated');
});

test('profile rejects unsupported photo types', function (): void {
    Storage::fake('public');

    $customer = Customer::factory()->registered()->create();

    $this->actingAs($customer, 'customer');

    Livewire::test(MyAccount::class)
        ->call('startEditing')
        ->set('profilePhoto', UploadedFile::fake()->create('avatar.gif', 100, 'image/gif'))
        ->call('save')
        ->assertHasErrors(['profilePhoto']);
});
