<?php

use App\Filament\Pages\PrinterSettings;
use App\Models\Permission;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->certificateFile = tempnam(sys_get_temp_dir(), 'qz-test-');
    config(['qz.certificate' => $this->certificateFile]);
});

afterEach(function (): void {
    if (is_file($this->certificateFile)) {
        unlink($this->certificateFile);
    }
});

function authorizePrinterSettings(): User
{
    $user = User::factory()->create();
    foreach (['ViewAny:Payment', 'View:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    test()->actingAs($user);

    return $user;
}

test('printer settings require payment viewing permissions', function (): void {
    $this->get(PrinterSettings::getUrl())->assertRedirect();
    $this->actingAs(User::factory()->create());
    $this->get(PrinterSettings::getUrl())->assertForbidden();
});

test('printer settings download the configured public certificate', function (): void {
    authorizePrinterSettings();
    $optionsFile = tempnam(sys_get_temp_dir(), 'qz-openssl-');
    try {
        file_put_contents($optionsFile, "[req]\ndistinguished_name=dn\n[dn]\n");
        $options = ['config' => $optionsFile, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'Printer Test'], $key, $options);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        openssl_x509_export($certificate, $pem);
        file_put_contents($this->certificateFile, $pem);

        Livewire::test(PrinterSettings::class)
            ->assertSee(['Download QZ Certificate', 'Unrecognized Certificate', 'override.crt'])
            ->call('downloadQzCertificate')
            ->assertFileDownloaded('digital-certificate.txt', $pem, 'text/plain');

        file_put_contents($this->certificateFile, $pem."\n-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----");
        Livewire::test(PrinterSettings::class)->call('downloadQzCertificate')
            ->assertNoFileDownloaded()
            ->assertNotified('QZ certificate is invalid. Contact your administrator.');
    } finally {
        unlink($optionsFile);
    }
});

test('printer settings refuse unavailable or invalid certificate downloads', function (string $state, string $message): void {
    authorizePrinterSettings();
    if ($state === 'missing') {
        unlink($this->certificateFile);
    } elseif ($state === 'directory') {
        config(['qz.certificate' => sys_get_temp_dir()]);
    } else {
        file_put_contents($this->certificateFile, "-----BEGIN CERTIFICATE-----\ninvalid\n-----END CERTIFICATE-----");
    }

    Livewire::test(PrinterSettings::class)->call('downloadQzCertificate')
        ->assertNoFileDownloaded()->assertNotified($message);
})->with([
    ['missing', 'QZ certificate is unavailable. Contact your administrator.'],
    ['directory', 'QZ certificate is unavailable. Contact your administrator.'],
    ['invalid', 'QZ certificate is invalid. Contact your administrator.'],
]);

test('printer certificate downloads recheck each permission', function (string $permission): void {
    $user = authorizePrinterSettings();
    $page = Livewire::test(PrinterSettings::class);
    $user->revokePermissionTo($permission);

    $page->call('downloadQzCertificate')->assertForbidden()->assertNoFileDownloaded();
})->with(['ViewAny:Payment', 'View:Payment']);

test('cashiers can choose browser QZ or RawBT in printer settings', function (): void {
    $user = User::factory()->create();
    foreach (['ViewAny:Payment', 'View:Payment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    $this->actingAs($user);
    Livewire::test(PrinterSettings::class)->assertOk()
        ->assertSee(['Browser Dialog', 'QZ Tray', 'RawBT Android', 'Save Settings', 'this browser'])
        ->assertSeeInOrder(['Save Settings', 'Start printing in 3 steps', '1. Connect the printer', '2. Set up your print method', '3. Save and print a receipt', 'Need help?'])
        ->assertSee(['POS-80 (Copy 1)', 'Saving settings alone does not test the connection.', 'Print Receipt'])
        ->assertSeeHtml('href="https://qz.io/download/"')
        ->assertSeeHtml('href="https://rawbt.ru/index.html"');
});
