<?php

use App\Enums\CustomerType;
use App\Enums\RecordStatus;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\Sectors\SectorResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Customer;
use App\Models\CustomerStatusLog;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actor = User::factory()->create();
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->actor);
});

function customerFormData(array $overrides = []): array
{
    return [...Customer::factory()->make()->only(['name', 'email', 'phone', 'is_active']), 'address' => 'SCCR', ...$overrides];
}

test('deleted customers can be viewed without restoring them', function (): void {
    $customer = Customer::factory()->create();
    $customer->delete();

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertOk()
        ->assertSee($customer->name);

    $this->assertSoftDeleted($customer);
});

test('deleted customer viewing still requires view permission', function (): void {
    $customer = Customer::factory()->create();
    $customer->delete();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('ViewAny:Customer', 'web'));
    $this->actingAs($viewer);

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertForbidden();
});

test('customer create leaves update audit empty and edit preserves creation audit', function (): void {
    $this->freezeTime();
    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData(['email' => 'audit@example.test']))
        ->assertHasNoActionErrors();
    $customer = Customer::where('email', 'audit@example.test')->firstOrFail();
    $createdAt = $customer->created_at;
    expect($customer->created_by)->toBe($this->actor->id)
        ->and($createdAt)->not->toBeNull()
        ->and($customer->updated_by)->toBeNull()
        ->and($customer->updated_at)->toBeNull();

    $this->travel(1)->minute();
    Livewire::test(ListCustomers::class)
        ->callTableAction('edit', $customer, data: customerFormData(['email' => $customer->email, 'name' => 'Edited Customer']))
        ->assertHasNoActionErrors();
    expect($customer->refresh()->created_at->equalTo($createdAt))->toBeTrue()
        ->and($customer->created_by)->toBe($this->actor->id)
        ->and($customer->updated_by)->toBe($this->actor->id)
        ->and($customer->updated_at->greaterThan($createdAt))->toBeTrue();
});

test('manual customer creation creates a profile without a login account', function (): void {
    $email = 'manual-customer@example.test';

    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData([
            'email' => $email,
            'name' => 'Manual Customer',
        ]))
        ->assertHasNoActionErrors();

    $customer = Customer::query()->where('email', $email)->firstOrFail();

    expect($customer->hasLoginAccount())->toBeFalse()
        ->and($customer->customer_type)->toBe(CustomerType::Regular)
        ->and(User::query()->where('email', $email)->exists())->toBeFalse();

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$customer]);
});

test('customer billing type can be created and edited independently of login access', function (): void {
    Livewire::test(ListCustomers::class)->callAction('create', data: customerFormData([
        'email' => 'invoice-customer@example.test',
        'customer_type' => CustomerType::Invoice->value,
    ]))->assertHasNoActionErrors();
    $customer = Customer::where('email', 'invoice-customer@example.test')->sole();

    expect($customer->customer_type)->toBe(CustomerType::Invoice)
        ->and($customer->hasLoginAccount())->toBeFalse();

    Livewire::test(ListCustomers::class)->callTableAction('edit', $customer, data: [
        'customer_type' => CustomerType::Regular->value,
    ])->assertHasNoActionErrors();

    expect($customer->refresh()->customer_type)->toBe(CustomerType::Regular);
});

test('customer type migration converts existing profiles and preserves invoice customers', function (): void {
    $regular = Customer::factory()->create();
    $invoice = Customer::factory()->invoice()->create();
    $migration = require database_path('migrations/2026_09_30_160009_rename_profile_only_customer_type_to_regular.php');

    $migration->down();
    expect(DB::table('customers')->where('id', $regular->id)->value('customer_type'))->toBe('profile_only');

    $migration->up();

    expect($regular->refresh()->customer_type)->toBe(CustomerType::Regular)
        ->and($invoice->refresh()->customer_type)->toBe(CustomerType::Invoice);

    $newCustomer = Customer::factory()->make();
    unset($newCustomer->customer_type);
    $newCustomer->save();
    expect($newCustomer->refresh()->customer_type)->toBe(CustomerType::Regular);
});

test('customer table exposes account and email verification state without exposing credentials', function (): void {
    $table = Livewire::test(ListCustomers::class)
        ->assertOk()
        ->instance()
        ->getTable();

    expect($table->getColumn('account_status'))->not->toBeNull()
        ->and($table->getColumn('email_verified_at'))->not->toBeNull()
        ->and(CustomerResource::exportDefinition()->columns)
        ->not->toHaveKey('password')
        ->not->toHaveKey('remember_token');
});

test('customer audit columns and export follow QA order', function (): void {
    $names = [
        'createdBy.name',
        'created_at',
        'updatedBy.name',
        'updated_at',
    ];
    $table = Livewire::test(ListCustomers::class)->instance()->getTable();
    expect(array_values(array_intersect(array_keys($table->getColumns()), $names)))->toBe($names)
        ->and(array_values(array_intersect(array_keys(CustomerResource::exportDefinition()->columns), $names)))->toBe($names);

    foreach ([
        CategoryResource::class,
        ProductResource::class,
        ProductVariantResource::class,
        SectorResource::class,
        UnitResource::class,
        UserResource::class,
        StockAdjustmentResource::class,
    ] as $resource) {
        expect(array_values(array_intersect(array_keys($resource::exportDefinition()->columns), $names)))->toBe($names);
    }

    foreach ([
        CustomerResource::class,
        CategoryResource::class,
        ProductResource::class,
        ProductVariantResource::class,
        SectorResource::class,
        UnitResource::class,
    ] as $resource) {
        expect(array_values(array_intersect(
            array_keys($resource::exportDefinition()->columns),
            ['deletedBy.name', 'deleted_at'],
        )))->toBe(['deletedBy.name', 'deleted_at']);
    }
});

test('order and payment exports include every table column', function (): void {
    foreach ([
        [ManageOrders::class, OrderResource::class],
        [ManagePayments::class, PaymentResource::class],
    ] as [$page, $resource]) {
        $tablePaths = array_keys(Livewire::test($page)->instance()->getTable()->getColumns());
        $exportPaths = array_keys($resource::exportDefinition()->columns);

        expect(array_diff($tablePaths, ['row_number'], $exportPaths))->toBe([]);
    }
});

test('customers sort new and edited records by latest activity', function (): void {
    $this->freezeTime();
    $old = Customer::factory()->create();
    $this->travel(1)->minute();
    $new = Customer::factory()->create();
    Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$new, $old], inOrder: true);
    $this->travel(1)->minute();
    $old->update(['name' => 'Recently edited']);
    Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$old, $new], inOrder: true);
});

test('deleted customer email can be reused on create and clone without copying orders', function (): void {
    $deleted = Customer::factory()->create(['email' => 'reusable@example.test']);
    $deleted->delete();
    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData(['email' => $deleted->email]))
        ->assertHasNoActionErrors();
    $created = Customer::where('email', $deleted->email)->firstOrFail();
    $created->delete();
    $source = Customer::factory()->create();
    Order::factory()->for($source)->create();
    Livewire::test(ListCustomers::class)
        ->callTableAction('clone', $source, data: customerFormData(['email' => $deleted->email]))
        ->assertHasNoActionErrors();
    $clone = Customer::where('email', $deleted->email)->firstOrFail();
    expect($clone->id)->not->toBe($source->id)
        ->and($clone->created_by)->toBe($this->actor->id)
        ->and($clone->updated_by)->toBeNull()
        ->and($clone->updated_at)->toBeNull()
        ->and($clone->orders()->count())->toBe(0);
});

test('saved customer email remains unique while drafts and blank emails may repeat', function (): void {
    Customer::factory()->create(['email' => 'unique@example.test']);
    expect(fn () => Customer::factory()->create(['email' => ' UNIQUE@example.test ']))->toThrow(ValidationException::class);
    Customer::factory()->create(['email' => 'unique@example.test', 'record_status' => RecordStatus::Draft]);
    Customer::factory()->count(2)->create(['email' => null]);
    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData(['email' => 'UNIQUE@example.test']))
        ->assertHasActionErrors(['email']);
});

test('saved customer phone remains unique and only Other Address uses a textarea', function (): void {
    Customer::factory()->create(['phone' => '08123456789']);

    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData(['phone' => '08123456789', 'address' => 'SCCR']))
        ->assertHasActionErrors(['phone']);

    $page = Livewire::test(ListCustomers::class)->mountAction('create');
    $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());
    $addressField = $schema->getComponent('address');

    $otherAddressField = $schema->getComponent('other_address', withHidden: true);

    expect($addressField)->toBeInstanceOf(Select::class)
        ->and($addressField->getOptions())->toBe([
            'SCCR' => 'SCCR',
            'Agung Putra University' => 'Agung Putra University',
            'Sains De Resto' => 'Sains De Resto',
            'Karenina Agung Resort' => 'Karenina Agung Resort',
            'Agung Farm & Garden' => 'Agung Farm & Garden',
            'Others' => 'Others',
        ])
        ->and($otherAddressField)->toBeInstanceOf(Textarea::class)
        ->and($otherAddressField->isVisible())->toBeFalse();

    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData(['address' => 'SCCR']))
        ->assertHasNoActionErrors();

    expect(Customer::query()->where('address', 'SCCR')->exists())->toBeTrue();

    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData([
            'address' => 'Others',
            'other_address' => "Customer warehouse\nSemarang",
        ]))
        ->assertHasNoActionErrors();

    $customer = Customer::query()->where('address', "Customer warehouse\nSemarang")->firstOrFail();

    Livewire::test(ListCustomers::class)
        ->callTableAction('edit', $customer, data: customerFormData([
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => 'Others',
            'other_address' => "Customer warehouse\nSurabaya",
        ]))
        ->assertHasNoActionErrors();

    expect($customer->refresh()->address)->toBe("Customer warehouse\nSurabaya");

    Livewire::test(ListCustomers::class)
        ->mountTableAction('edit', $customer)
        ->assertTableActionDataSet([
            'address' => 'Others',
            'other_address' => "Customer warehouse\nSurabaya",
        ]);
});

test('Other Address is required only when Others is selected', function (): void {
    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData(['address' => 'Others', 'other_address' => null]))
        ->assertHasActionErrors(['other_address']);

    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData(['address' => 'SCCR', 'other_address' => null]))
        ->assertHasNoActionErrors();
});

test('customer phone and address are optional on create and edit', function (): void {
    Livewire::test(ListCustomers::class)
        ->callAction('create', data: customerFormData([
            'name' => 'Customer Without Contact Details',
            'phone' => null,
            'address' => null,
        ]))
        ->assertHasNoActionErrors();

    $customer = Customer::query()->where('name', 'Customer Without Contact Details')->firstOrFail();

    expect($customer->phone)->toBeNull()
        ->and($customer->address)->toBeNull();

    $customerWithDetails = Customer::factory()->create(['address' => 'SCCR']);

    Livewire::test(ListCustomers::class)
        ->callTableAction('edit', $customerWithDetails, data: customerFormData([
            'name' => $customerWithDetails->name,
            'email' => $customerWithDetails->email,
            'phone' => null,
            'address' => null,
        ]))
        ->assertHasNoActionErrors();

    expect($customerWithDetails->refresh()->phone)->toBeNull()
        ->and($customerWithDetails->address)->toBeNull();
});

test('customer restore from deleted filter records actor status and time exactly once', function (): void {
    $customer = Customer::factory()->create();
    Livewire::test(ListCustomers::class)->callTableAction('delete', $customer)->assertHasNoActionErrors();
    $customer->refresh();
    Livewire::test(ListCustomers::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$customer])
        ->assertTableActionVisible('restore', $customer)
        ->callTableAction('restore', $customer)
        ->assertHasNoActionErrors();
    expect($customer->refresh()->trashed())->toBeFalse()
        ->and($customer->record_status)->toBe(RecordStatus::Saved);
    $log = $customer->statusLogs()->sole();
    expect($log->event)->toBe('restored')
        ->and($log->from_status)->toBe(RecordStatus::Deleted)
        ->and($log->to_status)->toBe(RecordStatus::Saved)
        ->and($log->changed_by_user_id)->toBe($this->actor->id)
        ->and($log->created_at)->not->toBeNull()
        ->and($customer->restore())->toBeFalse()
        ->and($customer->statusLogs()->count())->toBe(1);
});

test('restore email collision retains existing customer and restores deleted customer as draft', function (): void {
    $deleted = Customer::factory()->create(['email' => 'collision@example.test']);
    $deleted->delete();
    $saved = Customer::factory()->create(['email' => $deleted->email]);
    Livewire::test(ListCustomers::class)
        ->filterTable('trashed', true)
        ->callTableAction('restore', $deleted)
        ->assertHasNoActionErrors();
    expect($deleted->refresh()->record_status)->toBe(RecordStatus::Draft)
        ->and($saved->refresh()->record_status)->toBe(RecordStatus::Saved)
        ->and($deleted->statusLogs()->sole()->to_status)->toBe(RecordStatus::Draft);
});

test('customer restore rolls back when log persistence fails', function (): void {
    $customer = Customer::factory()->create();
    $customer->delete();
    $dispatcher = CustomerStatusLog::getEventDispatcher();
    CustomerStatusLog::setEventDispatcher(clone $dispatcher);
    try {
        CustomerStatusLog::creating(function (): void {
            throw new RuntimeException('Log unavailable');
        });
        expect(fn () => $customer->restore())->toThrow(RuntimeException::class, 'Log unavailable');
    } finally {
        CustomerStatusLog::setEventDispatcher($dispatcher);
    }
    expect($customer->refresh()->trashed())->toBeTrue()
        ->and($customer->record_status)->toBe(RecordStatus::Deleted)
        ->and($customer->statusLogs()->count())->toBe(0);
});

test('customer restore requires its dedicated permission', function (): void {
    $customer = Customer::factory()->create();
    $customer->delete();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('ViewAny:Customer', 'web'));
    $this->actingAs($viewer);
    Livewire::test(ListCustomers::class)->filterTable('trashed', true)->assertTableActionHidden('restore', $customer);
    expect($customer->refresh()->trashed())->toBeTrue()->and($customer->statusLogs()->count())->toBe(0);
});

test('customer detail shows scoped read only order history without old placeholder', function (): void {
    $customer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->create();
    $viewAction = Livewire::test(ListCustomers::class)->instance()->getTable()->getAction('view');
    expect($viewAction->record($customer)->getUrl())->toBe(CustomerResource::getUrl('view', ['record' => $customer]));
    $other = Order::factory()->create();
    $deleted = Order::factory()->for($customer)->create();
    $deleted->delete();
    Livewire::withoutLazyLoading()->test(ViewCustomer::class, ['record' => $customer->id])
        ->assertOk()->assertSee('Order History')->assertDontSee('Terdaftar Sejak')->assertDontSee('Not yet available');
    $history = Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$order])
        ->assertCanNotSeeTableRecords([$other, $deleted])
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
    $history->searchTable($order->order_number)->assertCanSeeTableRecords([$order]);
});

test('restored saved active customer returns to order dropdown while drafts and inactive stay excluded', function (): void {
    $customer = Customer::factory()->create();
    $customer->delete();
    $draft = Customer::factory()->create(['record_status' => RecordStatus::Draft]);
    $inactive = Customer::factory()->create(['is_active' => false]);
    $getOptions = fn (): array => Livewire::test(CreateOrder::class)
        ->instance()->form->getComponent('customer_id')->getOptions();
    expect($getOptions())->not->toHaveKey($customer->id);
    $customer->restore();
    expect($getOptions())->toHaveKey($customer->id)
        ->not->toHaveKey($draft->id)
        ->not->toHaveKey($inactive->id);
});

test('customer history paginates newest orders and shows an English empty state', function (): void {
    $customer = Customer::factory()->create();
    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertSee('No orders yet');
    $orders = Order::factory()->count(12)->for($customer)->sequence(fn ($sequence): array => [
        'created_at' => now()->subMinutes(12 - $sequence->index),
    ])->create();
    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->set('tableRecordsPerPage', 10)
        ->assertCanSeeTableRecords($orders->reverse()->take(10), inOrder: true)
        ->assertCanNotSeeTableRecords($orders->take(2));
});

test('customer order history links each order to its detail page, including cancelled orders', function (): void {
    $customer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->create();
    $cancelledOrder = Order::factory()->for($customer)->create(['status' => 'cancelled']);
    $page = Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$order, $cancelledOrder])
        ->assertTableActionDoesNotExist('view');

    expect($page->instance()->getTable()->getRecordUrl($order))
        ->toBe(OrderResource::getUrl('view', ['record' => $order]))
        ->and($page->instance()->getTable()->getRecordUrl($cancelledOrder))
        ->toBe(OrderResource::getUrl('view', ['record' => $cancelledOrder]));
});

test('customer history does not open orders without record viewing permission', function (): void {
    $customer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->create();
    $staff = User::factory()->create();
    foreach (['View:Customer', 'ViewAny:Order'] as $permission) {
        $staff->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($staff);
    $page = Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$order])
        ->assertTableActionDoesNotExist('view');

    expect($page->instance()->getTable()->getRecordUrl($order))->toBeNull();
});

test('customer buttons respect explicit staff permissions', function (): void {
    $customer = Customer::factory()->create();
    $staff = User::factory()->create();
    foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'Replicate'] as $ability) {
        $staff->givePermissionTo(Permission::findOrCreate($ability.':Customer', 'web'));
    }
    $this->actingAs($staff);
    Livewire::test(ListCustomers::class)
        ->assertActionVisible('create')
        ->assertTableActionVisible('edit', $customer)
        ->assertTableActionVisible('delete', $customer)
        ->assertTableActionVisible('clone', $customer);
    $staff->revokePermissionTo('Replicate:Customer');
    Livewire::test(ListCustomers::class)->assertTableActionHidden('clone', $customer);
});

test('customer create and clone actions do not offer draft saves', function (): void {
    $customer = Customer::factory()->create();

    Livewire::test(ListCustomers::class)
        ->assertActionHasLabel('create', 'Add Customer')
        ->mountAction('create')
        ->assertActionDoesNotExist('createDraft');

    Livewire::test(ListCustomers::class)
        ->mountTableAction('clone', $customer)
        ->assertActionDoesNotExist('saveDraft');
});

test('customer history requires order viewing permission', function (): void {
    $customer = Customer::factory()->create();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        Permission::findOrCreate('ViewAny:Customer', 'web'),
        Permission::findOrCreate('View:Customer', 'web'),
    ]);
    $this->actingAs($viewer);
    expect(OrdersRelationManager::canViewForRecord($customer, ViewCustomer::class))->toBeFalse();
    Livewire::test(ViewCustomer::class, ['record' => $customer->id])->assertDontSee('Order History');
    Livewire::withoutLazyLoading()->test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertForbidden();
});
