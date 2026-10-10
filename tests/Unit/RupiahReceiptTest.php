<?php

use App\Filament\Support\Rupiah;

test('receipt amounts omit only zero decimals', function (mixed $value, ?string $expected): void {
    expect(Rupiah::formatForReceipt($value))->toBe($expected);
})->with([
    ['60000.00', 'Rp 60.000'],
    ['0.00', 'Rp 0'],
    ['-5000.00', 'Rp -5.000'],
    ['60000.50', 'Rp 60.000,50'],
    [null, null],
]);

test('regular rupiah formatting keeps decimals', function (): void {
    expect(Rupiah::formatWithPrefix('60000.00'))->toBe('Rp 60.000,00');
});
