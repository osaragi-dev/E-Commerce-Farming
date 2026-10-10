<?php

use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PaymentSetting\PaymentSettingResource;
use App\Models\PaymentSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('super_admin', 'web');
    $this->actor = User::factory()->sso()->create();
    $this->actor->assignRole($role);
    $this->actingAs($this->actor);
});

test('payment settings provide a default singleton window', function (): void {
    $setting = PaymentSetting::current();

    expect($setting->proof_resubmission_hours)->toBe(24)
        ->and(PaymentSetting::current()->is($setting))->toBeTrue();
});

test('payment setting factory accepts a custom resubmission window', function (): void {
    $setting = PaymentSetting::factory()->create(['proof_resubmission_hours' => 48]);

    expect($setting->proof_resubmission_hours)->toBe(48);
});

test('payment settings are available as an authorized modal action on payments', function (): void {
    Livewire::test(ManagePayments::class)
        ->assertActionExists('paymentSettings')
        ->assertActionVisible('paymentSettings')
        ->mountAction('paymentSettings')
        ->assertActionMounted()
        ->assertActionDataSet([
            'proof_resubmission_hours' => 24,
        ]);
});

test('payment settings modal updates the window and audit actor', function (): void {
    $setting = PaymentSetting::current();

    Livewire::test(ManagePayments::class)
        ->callAction('paymentSettings', data: [
            'proof_resubmission_hours' => 48,
        ])
        ->assertHasNoActionErrors();

    expect($setting->refresh()->proof_resubmission_hours)->toBe(48)
        ->and($setting->updated_by)->toBe($this->actor->id)
        ->and($setting->updated_at)->not->toBeNull();
});

test('payment settings modal validates the upload window', function (mixed $hours): void {
    $setting = PaymentSetting::current();

    Livewire::test(ManagePayments::class)
        ->callAction('paymentSettings', data: [
            'proof_resubmission_hours' => $hours,
        ])
        ->assertHasActionErrors(['proof_resubmission_hours']);

    expect($setting->refresh()->proof_resubmission_hours)->toBe(24);
})->with([0, 721, null]);

test('payment settings action is hidden without update permission', function (): void {
    Permission::findOrCreate('ViewAny:Payment', 'web');
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('ViewAny:Payment');
    $this->actingAs($viewer);

    Livewire::test(ManagePayments::class)
        ->assertActionHidden('paymentSettings');
});

test('payment settings use their own update permission on the payments page', function (array $permissions, bool $canUpdateSettings): void {
    foreach (['ViewAny:Payment', 'Update:Payment', 'Update:PaymentSetting'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo(['ViewAny:Payment', ...$permissions]);
    $this->actingAs($user);

    $page = Livewire::test(ManagePayments::class)->assertSuccessful();

    if ($canUpdateSettings) {
        $page->assertActionVisible('paymentSettings')
            ->callAction('paymentSettings', data: ['proof_resubmission_hours' => 48])
            ->assertHasNoActionErrors();

        expect(PaymentSetting::current()->proof_resubmission_hours)->toBe(48);
    } else {
        $page->assertActionHidden('paymentSettings');
    }
})->with([
    'payment only' => [['Update:Payment'], false],
    'setting only' => [['Update:PaymentSetting'], true],
    'both' => [['Update:Payment', 'Update:PaymentSetting'], true],
]);

test('legacy payment settings URL redirects to payments', function (): void {
    $setting = PaymentSetting::current();

    $legacyUrl = PaymentSettingResource::getUrl('index', ['record' => $setting]);

    $response = $this->get(parse_url($legacyUrl, PHP_URL_PATH));

    $response->assertRedirect(PaymentResource::getUrl('index'));

    expect(PaymentSettingResource::shouldRegisterNavigation())->toBeFalse();
});
