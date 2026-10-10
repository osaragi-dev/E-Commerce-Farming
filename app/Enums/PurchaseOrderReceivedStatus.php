<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PurchaseOrderReceivedStatus: string implements HasColor, HasLabel
{
    case NotReceived = 'not_received';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case NotReceivedClosed = 'not_received_closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotReceived => 'Not Received',
            self::PartiallyReceived => 'Partially Received',
            self::Received => 'Received',
            self::NotReceivedClosed => 'Not Received (Closed)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotReceived => 'gray',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::NotReceivedClosed => 'danger',
        };
    }
}
