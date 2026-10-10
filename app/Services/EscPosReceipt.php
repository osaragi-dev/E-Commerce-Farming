<?php

namespace App\Services;

use App\Filament\Support\Rupiah;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Quantity;
use Illuminate\Support\Str;

class EscPosReceipt
{
    public function raw(Payment|Order $payment): string
    {
        $data = $this->data($payment);
        $image = imagecreatefromstring(base64_decode($data[1]['data']));
        $widthBytes = (int) ceil(imagesx($image) / 8);
        $height = imagesy($image);
        $raster = "\x1Dv0\x00".pack('vv', $widthBytes, $height);

        for ($y = 0; $y < $height; $y++) {
            for ($column = 0; $column < $widthBytes; $column++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $column * 8 + $bit;
                    if ($x < imagesx($image) && (imagecolorat($image, $x, $y) & 0xFF) < 160) {
                        $byte |= 1 << (7 - $bit);
                    }
                }
                $raster .= chr($byte);
            }
        }

        return base64_decode($data[0]['data']).$raster.base64_decode($data[2]['data']);
    }

    /** @return list<array<string, mixed>> */
    public function data(Payment|Order $payment): array
    {
        $isOrder = $payment instanceof Order;
        $order = $isOrder ? $payment : $payment->order;
        $payment = $isOrder ? $order->payment : $payment;
        $separator = str_repeat('-', 48)."\n";
        $text = "\n\x1Ba\x00\x1BM\x00\x1BE\x00\x1D!\x00";
        $text .= $this->columns('Order', $order->order_number);
        if ($isOrder) {
            $text .= $this->columns('Issued', $order->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i').' WIB');
            $text .= $this->columns('Customer', $order->recipient_name ?? '-');
            $text .= $this->columns('Phone', $order->recipient_phone ?? '-');
            $text .= $this->columns('Shipping Method', $order->shipping_method?->getLabel() ?? '-');
            $text .= $this->columns($order->shipping_method?->getLocationLabel() ?? 'Shipping Location', $order->shipping_address ?? '-');
        } else {
            $text .= $this->columns('Payment', $payment->payment_number);
            $text .= $this->columns('Paid at', $payment->verified_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB');
            if (filled($payment->verifiedBy?->name)) {
                $text .= $this->columns('Cashier', $payment->verifiedBy->name);
            }
            $text .= $this->columns('Customer', filled($order->recipient_name) ? $order->recipient_name : ($order->customer?->name ?? '-'));
        }
        $text .= $separator;

        foreach ($order->items as $item) {
            $text .= "\x1BE\x01".wordwrap($this->clean(filled($item->variant_name) ? $item->variant_name : '-'), 48, "\n", true)."\n\x1BE\x00";
            $text .= $this->columns(
                Quantity::format($item->quantity).' '.($item->unit ?? ($isOrder ? 'pcs' : '')).' x '.Rupiah::formatForReceipt($item->unit_price),
                Rupiah::formatForReceipt($item->subtotal),
            )."\n";
        }

        $text .= $separator;
        $text .= $this->columns('Subtotal', Rupiah::formatForReceipt($order->subtotal));
        if ((float) $order->discount_amount > 0) {
            $text .= $this->columns('Discount', '-'.Rupiah::formatForReceipt($order->discount_amount));
        }
        if ((float) $order->shipping_cost > 0) {
            $text .= $this->columns('Shipping', Rupiah::formatForReceipt($order->shipping_cost));
        }
        $text .= $separator;
        $text .= "\x1BE\x01".$this->columns('TOTAL', Rupiah::formatForReceipt($order->total))."\x1BE\x00";
        $text .= "\x1BE\x01".$this->columns('Payment Method', $payment?->method?->getLabel() ?? '-')."\x1BE\x00";
        $text .= $separator."\n\x1Ba\x01Thank you for your purchase!\nAgung Farm & Garden\n";

        return [
            ['type' => 'raw', 'format' => 'command', 'flavor' => 'base64', 'data' => base64_encode("\x1B@\x1Ba\x01")],
            ['type' => 'raw', 'format' => 'image', 'flavor' => 'base64', 'data' => $this->logo(), 'options' => ['language' => 'ESCPOS', 'dotDensity' => 'double']],
            ['type' => 'raw', 'format' => 'command', 'flavor' => 'base64', 'data' => base64_encode($text."\n\n\n\x1DV\x00")],
        ];
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', Str::ascii($value)));
    }

    private function columns(string $label, string $value): string
    {
        $value = $this->clean($value);
        $rightWidth = min(31, max(18, strlen($value)));
        $leftWidth = 48 - $rightWidth - 1;
        $left = explode("\n", wordwrap($this->clean($label), $leftWidth, "\n", true));
        $right = explode("\n", wordwrap($value, $rightWidth, "\n", true));
        $result = '';

        for ($line = 0; $line < max(count($left), count($right)); $line++) {
            $result .= str_pad($left[$line] ?? '', $leftWidth).' '.str_pad($right[$line] ?? '', $rightWidth, ' ', STR_PAD_LEFT)."\n";
        }

        return $result;
    }

    private function logo(): string
    {
        $source = imagecreatefrompng(public_path('images/logo/agung-farm-receipt-logo.png'));
        $height = (int) round(imagesy($source) * 240 / imagesx($source));
        $image = imagecreatetruecolor(240, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, 240, $height, imagesx($source), imagesy($source));
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        ob_start();
        imagepng($image);

        return base64_encode(ob_get_clean());
    }
}
