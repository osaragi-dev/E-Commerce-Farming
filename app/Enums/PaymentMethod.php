<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Qris = 'qris';
    case BankTransfer = 'bank_transfer';
    case Invoice = 'invoice';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Qris => 'QRIS',
            self::BankTransfer => 'Bank Transfer',
            self::Invoice => 'Invoice',
        };
    }

    public function requiresProof(): bool
    {
        return in_array($this, [self::Qris, self::BankTransfer], true);
    }
}
