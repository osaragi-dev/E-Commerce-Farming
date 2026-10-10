<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockAdjustmentReason: string implements HasLabel
{
    case StokOpname = 'stok_opname';
    case Rusak = 'rusak';
    case Hilang = 'hilang';
    case Lainnya = 'lainnya';

    public function getLabel(): string
    {
        return match ($this) {
            self::StokOpname => 'Add Stock',
            self::Rusak => 'Damaged',
            self::Hilang => 'Lost',
            self::Lainnya => 'Other',
        };
    }

    /**
     * TCS revision: dropdown Reason cuma tampilkan 3 pilihan (Add Stock,
     * Damaged, Lost) buat data BARU. 'Lainnya'/Other tidak dihapus dari
     * enum-nya sendiri — cuma disembunyikan dari pilihan form — supaya
     * data Stock Adjustment LAMA yang mungkin masih pakai reason itu
     * tetap bisa ditampilkan dengan benar, tidak error.
     *
     * @return array<self>
     */
    public static function selectableCases(): array
    {
        return [self::StokOpname, self::Rusak, self::Hilang];
    }
}
