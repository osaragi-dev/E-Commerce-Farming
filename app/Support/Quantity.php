<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

final class Quantity
{
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? str_replace(',', '.', trim($value)) : $value;
    }

    /** @return list<string> */
    public static function rules(bool $allowZero = false): array
    {
        return ['numeric', 'regex:/^\d+(?:\.\d{1,3})?$/D', $allowZero ? 'min:0' : 'min:0.001', 'max:999999999999999.999'];
    }

    public static function validated(mixed $value, string $field = 'quantity', bool $allowZero = false): string
    {
        $value = self::normalize($value);
        Validator::make([$field => $value], [$field => ['required', ...self::rules($allowZero)]])->validate();

        return bcadd((string) $value, '0', 3);
    }

    public static function add(string|int|float $left, string|int|float $right): string
    {
        return bcadd((string) $left, (string) $right, 3);
    }

    public static function subtract(string|int|float $left, string|int|float $right): string
    {
        return bcsub((string) $left, (string) $right, 3);
    }

    public static function format(string|int|float|null $value): string
    {
        return rtrim(rtrim(bcadd((string) ($value ?? 0), '0', 3), '0'), '.');
    }
}
