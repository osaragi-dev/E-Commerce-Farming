<?php

namespace App\Support;

final class VariantPricing
{
    public static function subtotal(string $price, string|int|float $quantity, string|int|float $priceQuantity = '1'): string
    {
        return bcround(bcdiv(bcmul($price, (string) $quantity, 5), (string) $priceQuantity, 3), 2, \RoundingMode::HalfAwayFromZero);
    }
}
