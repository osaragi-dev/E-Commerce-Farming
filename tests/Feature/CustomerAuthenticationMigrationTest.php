<?php

use App\Enums\RecordStatus;
use App\Livewire\Auth\Login;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

test('customer authentication migration moves linked and orphan customer users', function (): void {
    Schema::table('customers', function (Blueprint $table): void {
        $table->uuid('user_id')->nullable();
    });
    Schema::table('carts', function (Blueprint $table): void {
        $table->uuid('user_id')->nullable()->unique();
    });

    $customerRole = Role::findOrCreate('customer', 'web');
    $linkedUser = User::factory()->create([
        'email' => 'legacy-linked@example.test',
        'password' => 'legacy-password',
        'google_id' => 'legacy-google-linked',
        'email_verified_at' => now(),
        'is_active' => true,
    ]);
    $linkedUser->assignRole($customerRole);

    $orphanUser = User::factory()->create([
        'email' => 'legacy-orphan@example.test',
        'password' => 'orphan-password',
        'email_verified_at' => null,
        'is_active' => false,
    ]);
    $orphanUser->assignRole($customerRole);

    $linkedCustomer = Customer::factory()->create([
        'name' => 'Linked Customer Profile',
        'email' => $linkedUser->email,
        'phone' => '08111111111',
    ]);
    $linkedCustomer->delete();
    DB::table('customers')->where('id', $linkedCustomer->id)->update(['user_id' => $linkedUser->id]);

    $cart = Cart::factory()->create(['customer_id' => $linkedCustomer->id]);
    DB::table('carts')->where('id', $cart->id)->update(['user_id' => $linkedUser->id]);

    $auditOrder = Order::factory()->create([
        'customer_id' => $linkedCustomer->id,
        'created_by' => $linkedUser->id,
    ]);

    $migration = require database_path('migrations/2026_09_19_143221_migrate_customer_authentication_from_users.php');
    $migration->up();

    $linkedCustomer->refresh();
    $orphanCustomer = Customer::query()->where('email', $orphanUser->email)->sole();

    expect(Hash::check('legacy-password', $linkedCustomer->password))->toBeTrue()
        ->and($linkedCustomer->google_id)->toBe('legacy-google-linked')
        ->and($linkedCustomer->hasVerifiedEmail())->toBeTrue()
        ->and($linkedCustomer->trashed())->toBeFalse()
        ->and($orphanCustomer->is_active)->toBeFalse()
        ->and($orphanCustomer->hasLoginAccount())->toBeTrue()
        ->and(Hash::check('orphan-password', $orphanCustomer->password))->toBeTrue()
        ->and(Cart::query()->whereKey($cart->id)->value('customer_id'))->toBe($linkedCustomer->id)
        ->and(DB::table('orders')->where('id', $auditOrder->id)->value('created_by'))->toBeNull()
        ->and(User::query()->whereIn('id', [$linkedUser->id, $orphanUser->id])->count())->toBe(0)
        ->and(Role::query()->where('name', 'customer')->exists())->toBeFalse()
        ->and(Schema::hasColumn('customers', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumn('carts', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'password'))->toBeTrue();

    expect(fn (): Cart => Cart::factory()->create(['customer_id' => $linkedCustomer->id]))
        ->toThrow(QueryException::class);
});

test('internal admin repair preserves the account with local access and keeps the customer profile', function (): void {
    $customer = Customer::factory()->registered()->create([
        'name' => 'Admin',
        'email' => 'admin@sccr.id',
        'phone' => '08123456789',
    ]);

    $migration = require database_path('migrations/2026_09_19_155729_repair_internal_admin_account_after_customer_auth_migration.php');
    $migration->up();
    $migration->up();

    $user = User::query()->where('email', 'admin@sccr.id')->sole();
    $customer->refresh();

    expect(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->hasRole('super_admin'))->toBeTrue()
        ->and($user->record_status)->toBe(RecordStatus::Saved)
        ->and($user->is_active)->toBeTrue()
        ->and($user->sso_id)->toBeNull()
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($customer->hasLoginAccount())->toBeFalse()
        ->and($customer->password)->toBeNull()
        ->and(Customer::query()->where('email', 'admin@sccr.id')->count())->toBe(1);

    $this->get('/admin/login')->assertOk();

    Livewire::test(Login::class)
        ->set('email', 'admin@sccr.id')
        ->set('password', 'password123')
        ->call('login')
        ->assertHasErrors(['email']);

    $this->assertGuest('web');
});
