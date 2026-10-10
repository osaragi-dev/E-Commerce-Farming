<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->signingDirectory = sys_get_temp_dir().'/qz-test-'.Str::uuid();
    config([
        'qz.printer' => 'POS-80 11.3.0.0 USB',
        'qz.certificate' => $this->signingDirectory.'/certificate.txt',
        'qz.private_key' => $this->signingDirectory.'/key.pem',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->signingDirectory);
});

function authorizePaymentPrinting(): User
{
    Permission::findOrCreate('View:Payment', 'web');
    $user = User::factory()->sso()->create();
    $user->assignRole(Role::findOrCreate('printer_operator', 'web'));
    $user->givePermissionTo('View:Payment');
    test()->actingAs($user);

    return $user;
}

test('QZ endpoints require authentication and payment view permission', function (): void {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    $this->postJson(route('admin.payments.print', $payment))->assertRedirect();
    $this->getJson(route('admin.payments.print-certificate', $payment))->assertRedirect();
    $this->postJson(route('admin.payments.rawbt', $payment))->assertRedirect();
    $unauthorized = User::factory()->sso()->create();
    $unauthorized->assignRole(Role::findOrCreate('printer_operator', 'web'));
    $this->actingAs($unauthorized);
    $this->postJson(route('admin.payments.print', $payment))->assertForbidden();
    $this->getJson(route('admin.payments.print-certificate', $payment))->assertForbidden();
    $this->postJson(route('admin.payments.rawbt', $payment))->assertForbidden();
});

test('QZ refuses a payment that is no longer paid', function (PaymentStatus $status): void {
    authorizePaymentPrinting();
    $payment = Payment::factory()->create(['status' => $status]);
    $this->postJson(route('admin.payments.print', $payment))->assertForbidden();
    $this->getJson(route('admin.payments.print-certificate', $payment))->assertForbidden();
    $this->postJson(route('admin.payments.rawbt', $payment))->assertForbidden();
})->with([PaymentStatus::Unpaid, PaymentStatus::WaitingConfirm, PaymentStatus::Rejected, PaymentStatus::Void, PaymentStatus::UnpaidCancel]);

test('QZ builds one USB receipt from stored data and strips injected printer commands', function (): void {
    authorizePaymentPrinting();
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    OrderItem::factory()->for($payment->order)->create([
        'product_name' => 'Hidden parent product', 'variant_name' => "Fresh\x1B\x70\x00Garden".str_repeat('Long variant ', 12),
        'quantity' => 2, 'unit_price' => 25000, 'subtotal' => 50000,
    ]);
    $original = $payment->fresh()->getAttributes();

    $this->getJson(route('admin.payments.print-certificate', $payment))->assertOk()->assertExactJson(['certificate' => null]);
    $job = $this->postJson(route('admin.payments.print', $payment), [
        'printer' => ['host' => 'attacker'], 'data' => 'forged receipt', 'copies' => 20,
    ])->assertOk()->assertJsonPath('params.printer.name', 'POS-80 11.3.0.0 USB')
        ->assertJsonPath('params.options.copies', 1)->assertJsonPath('signature', null)->json();

    $data = $job['params']['data'];
    $text = base64_decode($data[2]['data']);
    expect($text)->toContain($payment->payment_number, 'Rp 25.000', 'Rp 50.000', "\x1DV\x00")
        ->not->toContain("\x1B\x70\x00", 'forged receipt', 'Hidden parent product');
    expect(getimagesizefromstring(base64_decode($data[1]['data']))[0])->toBe(240);
    expect($payment->fresh()->getAttributes())->toBe($original);
});

test('QZ signs only its generated receipt and never returns the private key', function (): void {
    authorizePaymentPrinting();
    File::makeDirectory($this->signingDirectory);
    $configPath = $this->signingDirectory.'/openssl.cnf';
    File::put($configPath, "[req]\ndistinguished_name=dn\n[dn]\n");
    $options = ['config' => $configPath, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => 'Receipt Test'], $key, $options);
    $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
    openssl_x509_export_to_file($certificate, config('qz.certificate'));
    openssl_pkey_export_to_file($key, config('qz.private_key'), null, $options);
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);

    $job = $this->postJson(route('admin.payments.print', $payment))->assertOk()
        ->assertDontSee('PRIVATE KEY')->json();
    $message = json_encode(['call' => 'print', 'params' => $job['params'], 'timestamp' => $job['timestamp']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    expect(openssl_verify(hash('sha256', $message), base64_decode($job['signature']), $certificate, OPENSSL_ALGO_SHA512))->toBe(1);
    $this->getJson(route('admin.payments.print-certificate', $payment))->assertOk()
        ->assertSee('CERTIFICATE')->assertDontSee('PRIVATE KEY');
});

test('incomplete signing configuration fails instead of silently downgrading', function (): void {
    authorizePaymentPrinting();
    File::makeDirectory($this->signingDirectory);
    File::put(config('qz.certificate'), 'incomplete');
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    $this->postJson(route('admin.payments.print', $payment))->assertStatus(503);
    $this->getJson(route('admin.payments.print-certificate', $payment))->assertStatus(503);
});

test('QZ uses the selected printer name without accepting arbitrary printer destinations', function (): void {
    authorizePaymentPrinting();
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    $this->postJson(route('admin.payments.print', $payment), ['printer_name' => 'Cashier USB'])
        ->assertOk()->assertJsonPath('params.printer', ['name' => 'Cashier USB']);
    $this->postJson(route('admin.payments.print', $payment), ['printer_name' => ['file' => 'output.txt']])
        ->assertRedirect()->assertSessionHasErrors('printer_name');
    $this->postJson(route('admin.payments.print', $payment), ['printer_name' => "Printer\nInjected"])
        ->assertRedirect()->assertSessionHasErrors('printer_name');
});

test('RawBT returns ESC POS bytes including the logo without needing QZ signing', function (): void {
    authorizePaymentPrinting();
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    OrderItem::factory()->for($payment->order)->create(['product_name' => 'Hidden parent product', 'variant_name' => 'RawBT garden box']);
    $original = $payment->fresh()->getAttributes();
    $response = $this->postJson(route('admin.payments.rawbt', $payment))->assertOk();
    $raw = base64_decode($response->json('data'), true);
    expect($raw)->toStartWith("\x1B@\x1Ba\x01\x1Dv0\x00")
        ->toContain($payment->payment_number, 'RawBT garden box')
        ->not->toContain('Hidden parent product')
        ->toEndWith("\x1DV\x00");
    $width = unpack('v', substr($raw, 9, 2))[1];
    expect($width)->toBe(30);
    expect($payment->fresh()->getAttributes())->toBe($original);
});

test('raw receipts align amounts right and show payment method with readable wrapped details', function (PaymentMethod $method): void {
    authorizePaymentPrinting();
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid, 'method' => $method, 'amount' => 55000]);
    $payment->order->update([
        'recipient_name' => str_repeat('Long Customer Name ', 5),
        'subtotal' => 50000, 'shipping_cost' => 5000, 'total' => 55000,
    ]);
    OrderItem::factory()->for($payment->order)->create([
        'product_name' => str_repeat('Fresh garden vegetables ', 5),
        'variant_name' => str_repeat('Large harvest box ', 4),
        'quantity' => 2, 'unit' => 'box', 'unit_price' => 25000, 'subtotal' => 50000,
    ]);

    $job = $this->postJson(route('admin.payments.print', $payment))->assertOk()->json();
    $commands = base64_decode($job['params']['data'][2]['data']);
    expect($commands)->toContain("\x1Ba\x01", "\x1BE\x01TOTAL");
    $text = preg_replace('/\x1B[aME][\x00-\x01]|\x1D![\x00-\x01]|\x1DV\x00/', '', $commands);
    expect($text)->toContain('Payment Method'.str_repeat(' ', 34 - strlen($method->getLabel())).$method->getLabel())
        ->toContain('Subtotal'.str_repeat(' ', 31).'Rp 50.000')
        ->toContain('TOTAL'.str_repeat(' ', 34).'Rp 55.000');
    foreach (explode("\n", $text) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(48);
    }
})->with([PaymentMethod::Cash, PaymentMethod::Qris]);

test('raw receipt shows cashier only when a verifier is recorded', function (bool $hasCashier): void {
    $actor = authorizePaymentPrinting();
    $payment = Payment::factory()->create([
        'status' => PaymentStatus::Paid,
        'verified_by' => $hasCashier ? $actor->id : null,
    ]);

    $job = $this->postJson(route('admin.payments.print', $payment))->assertOk()->json();
    $text = base64_decode($job['params']['data'][2]['data']);

    if ($hasCashier) {
        expect($text)->toContain('Cashier', Str::ascii($actor->name));
    } else {
        expect($text)->not->toContain('Cashier');
    }
})->with([true, false]);
