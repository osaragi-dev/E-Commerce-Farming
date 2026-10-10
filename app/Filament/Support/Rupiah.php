<?php

namespace App\Filament\Support;

use Illuminate\Support\Str;

final class Rupiah
{
    public static function normalize(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $normalized = Str::of((string) $value)
            ->trim()
            ->replace(['Rp', ' ', "\u{00A0}"], '')
            ->toString();

        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',')) {
            return Str::of($normalized)
                ->replace('.', '')
                ->replace(',', '.')
                ->toString();
        }

        if (preg_match('/^-?\d{1,3}(?:\.\d{3})+$/', $normalized) === 1) {
            return Str::of($normalized)->replace('.', '')->toString();
        }

        return $normalized;
    }

    public static function format(mixed $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return null;
        }

        if (preg_match('/^(?<sign>-?)(?<integer>\d+)(?:\.(?<decimal>\d{1,2}))?$/', $normalized, $parts) !== 1) {
            return $normalized;
        }

        $integer = ltrim($parts['integer'], '0');
        $integer = $integer === '' ? '0' : $integer;
        $groupedInteger = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer) ?? $integer;
        $decimal = str_pad($parts['decimal'] ?? '', 2, '0');

        return $parts['sign'].$groupedInteger.','.$decimal;
    }

    public static function formatWithPrefix(mixed $value): ?string
    {
        $formatted = self::format($value);

        return $formatted === null ? null : "Rp {$formatted}";
    }

    public static function formatForReceipt(mixed $value): ?string
    {
        $formatted = self::formatWithPrefix($value);

        return $formatted === null ? null : preg_replace('/,00$/', '', $formatted);
    }
}
