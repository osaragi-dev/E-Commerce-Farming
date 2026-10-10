<?php

namespace App\Filament\Exports;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentExportService
{
    public const array COLUMNS = [
        'Type', 'Order Number', 'Payment Date', 'Created By', 'Customer', 'Item Count',
        'Payment Method', 'Payment Status', 'Subtotal', 'Shipping Cost', 'Total',
        'Payment Amount', 'Reference', 'Order Note', 'Product', 'Variant', 'Unit',
        'Unit Price', 'Quantity', 'Line Total', 'Price Quantity',
    ];

    public const array MONEY_COLUMNS = [8, 9, 10, 11, 17, 19];

    /** @return iterable<list<float|int|string|null>> */
    public function rows(Payment $payment, bool $forSpreadsheet = false): iterable
    {
        $values = app(TableExportService::class);
        $value = fn (string $path): mixed => $values->value($payment, $path, $forSpreadsheet);
        $order = $payment->order;
        $date = $payment->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s');
        yield [
            'Transaction', $value('order.order_number'), $date, $value('createdBy.name'),
            $value('order.recipient_name'), $order?->items->count() ?? 0,
            $value('method'), $value('status'),
            $order === null ? null : (float) $order->subtotal,
            $order === null ? null : (float) $order->shipping_cost,
            $order === null ? null : (float) $order->total,
            (float) $payment->amount, $value('payment_number'), $value('order.notes'),
            null, null, null, null, null, null, null,
        ];

        foreach ($order?->items ?? [] as $item) {
            yield [
                'Item', $value('order.order_number'), $date,
                null, null, null, null, null, null, null, null, null, null, null,
                $values->value($item, 'product_name', $forSpreadsheet),
                $values->value($item, 'variant_name', $forSpreadsheet),
                $values->value($item, 'unit', $forSpreadsheet),
                (float) $item->unit_price, (float) $item->quantity, (float) $item->subtotal, (float) $item->price_quantity,
            ];
        }
    }

    /** @param Builder<Payment> $payments */
    public function download(Builder $payments, string $start, string $end): StreamedResponse
    {
        return response()->streamDownload(function () use ($payments): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            try {
                $sheet = $writer->getCurrentSheet();
                $sheet->setName('Payments');
                $sheet->setColumnWidthForRange(18, 1, count(self::COLUMNS));
                $sheet->setColumnWidth(26, 2, 3, 13);
                $sheet->setColumnWidth(32, 5, 14, 15);
                $sheet->setPrintTitleRows('1:1');
                $header = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('1F2937');
                $transaction = (new Style)->setFontBold()->setBackgroundColor('E5E7EB');
                $item = (new Style)->setFontColor('4B5563');
                $money = (new Style)->setFormat('#,##0.00');
                $columnStyles = array_fill_keys(self::MONEY_COLUMNS, $money);
                $writer->addRow(Row::fromValues(self::COLUMNS, $header));
                foreach ((clone $payments)->with(['order.items' => fn (HasMany $query): HasMany => $query->orderBy('id'), 'createdBy'])->lazy(500) as $payment) {
                    foreach ($this->rows($payment, true) as $row) {
                        $writer->addRow(Row::fromValuesWithStyles($row, $row[0] === 'Transaction' ? $transaction : $item, $columnStyles));
                    }
                }
            } finally {
                $writer->close();
            }
        }, "payments-{$start}-to-{$end}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
