<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;

class QzReceipt
{
    public function __construct(private EscPosReceipt $receipt) {}

    public function certificate(): ?string
    {
        $certificate = config('qz.certificate');
        $key = config('qz.private_key');

        if (! is_file($certificate) && ! is_file($key)) {
            return null;
        }

        abort_unless(is_readable($certificate) && is_readable($key), 503, 'QZ signing files are incomplete or unreadable.');

        return file_get_contents($certificate);
    }

    /** @return array{params: array<string, mixed>, timestamp: int, digest: string, signature: ?string} */
    public function job(Payment|Order $payment, ?string $printerName = null): array
    {
        $params = [
            'printer' => ['name' => $printerName ?? config('qz.printer')],
            'options' => $this->options($payment),
            'data' => $this->receipt->data($payment),
        ];
        $timestamp = now()->getTimestampMs();
        $message = json_encode(['call' => 'print', 'params' => $params, 'timestamp' => $timestamp], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $digest = hash('sha256', $message);
        $signature = null;

        if ($this->certificate() !== null) {
            $key = openssl_pkey_get_private(file_get_contents(config('qz.private_key')));
            abort_unless($key !== false, 503, 'QZ signing key is invalid.');
            abort_unless(openssl_x509_check_private_key($this->certificate(), $key), 503, 'QZ certificate and signing key do not match.');

            abort_unless(openssl_sign($digest, $bytes, $key, OPENSSL_ALGO_SHA512), 503, 'Unable to sign the receipt.');
            $signature = base64_encode($bytes);
        }

        return compact('params', 'timestamp', 'digest', 'signature');
    }

    /** @return array<string, mixed> */
    private function options(Payment|Order $payment): array
    {
        // Keep the complete QZ 2.3 configuration in SDK order for pre-signed jobs.
        return [
            'bounds' => null, 'colorType' => 'color', 'copies' => 1, 'density' => 0,
            'duplex' => false, 'fallbackDensity' => null, 'interpolation' => 'bicubic',
            'jobName' => 'Receipt '.($payment instanceof Order ? $payment->order_number : $payment->payment_number), 'legacy' => false, 'margins' => 0,
            'orientation' => null, 'paperThickness' => null, 'printerTray' => null,
            'rasterize' => false, 'rotation' => 0, 'scaleContent' => true, 'size' => null,
            'units' => 'in', 'forceRaw' => true, 'encoding' => 'ISO-8859-1', 'spool' => null,
        ];
    }
}
