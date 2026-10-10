<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrinterSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.pages.printer-settings';

    public function downloadQzCertificate(): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $path = config('qz.certificate');
        $certificate = is_string($path) && is_file($path) && is_readable($path)
            ? @file_get_contents($path)
            : false;

        if ($certificate === false) {
            Notification::make()->danger()->title('QZ certificate is unavailable. Contact your administrator.')->send();

            return null;
        }

        if (! preg_match('/\A\s*-----BEGIN CERTIFICATE-----\s+[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----\s*\z/', $certificate)
            || @openssl_x509_read($certificate) === false) {
            Notification::make()->danger()->title('QZ certificate is invalid. Contact your administrator.')->send();

            return null;
        }

        return response()->streamDownload(static function () use ($certificate): void {
            echo $certificate;
        }, 'digital-certificate.txt', ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    public static function canAccess(): bool
    {
        return auth('web')->user()?->can('ViewAny:Payment')
            && auth('web')->user()?->can('View:Payment');
    }
}
