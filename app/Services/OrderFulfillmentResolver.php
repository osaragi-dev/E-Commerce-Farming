<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Collection;

final class OrderFulfillmentResolver
{
    /** @return Collection<int, Warehouse> */
    public function candidates(): Collection
    {
        return Warehouse::fulfillmentCandidates()->get();
    }

    /**
     * @param  iterable<array{variant: ProductVariant, quantity: int|string}>  $lines
     */
    public function resolveForLines(iterable $lines): ?Warehouse
    {
        $lines = collect($lines)->values();

        if ($lines->isEmpty()) {
            return null;
        }

        $quantities = $lines->groupBy(fn (array $line): string => $line['variant']->id)
            ->map(fn ($variantLines): array => [
                'variant' => $variantLines->first()['variant'],
                'quantity' => $variantLines->reduce(fn (string $total, array $line): string => Quantity::add($total, $line['quantity']), '0.000'),
            ]);
        $reservationService = app(OrderStockReservationService::class);

        return $this->candidates()->first(
            fn (Warehouse $warehouse): bool => $quantities->every(
                fn (array $line): bool => $reservationService->availableForReservation(
                    $line['variant'],
                    $warehouse,
                ) >= $line['quantity'],
            ),
        );
    }
}
