<?php

namespace App\Services;

use App\Filament\Support\Rupiah;
use App\Models\PurchaseOrder;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class PurchaseOrderDocumentService
{
    public function docx(PurchaseOrder $purchaseOrder): string
    {
        $purchaseOrder->loadMissing(['purchaseRequest', 'warehouse', 'vendor', 'items.item']);
        $document = new PhpWord;
        $section = $document->addSection();
        $section->addTitle('Purchase Order', 1);
        $section->addText('PO Number: '.($purchaseOrder->po_number ?? '-'));
        $section->addText('PO Date: '.$purchaseOrder->po_date?->format('d/m/Y'));
        $section->addText('Purchase Request: '.($purchaseOrder->purchaseRequest?->pr_number ?? '-'));
        $section->addText('Location: '.($purchaseOrder->warehouse?->name ?? '-'));
        $section->addText('Vendor: '.($purchaseOrder->vendor?->name ?? '-'));
        $section->addText('Expected Delivery: '.$purchaseOrder->expected_delivery_date?->format('d/m/Y'));
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);
        $table->addRow();

        foreach (['No', 'Item', 'Qty', 'Unit', 'Unit Price', 'Total Price'] as $heading) {
            $table->addCell(1600, ['bgColor' => 'E5E7EB'])->addText($heading, ['bold' => true]);
        }

        foreach ($purchaseOrder->items as $index => $item) {
            $table->addRow();
            $table->addCell(600)->addText((string) ($index + 1));
            $table->addCell(4400)->addText($item->item ? trim($item->item->code.' - '.$item->item->name) : '-');
            $table->addCell(1000)->addText((string) $item->qty_order);
            $table->addCell(1000)->addText($item->unit_code);
            $table->addCell(1800)->addText(Rupiah::formatWithPrefix($item->unit_price) ?? 'Rp 0');
            $table->addCell(1800)->addText(Rupiah::formatWithPrefix($item->total_price) ?? 'Rp 0');
        }

        $section->addText('Subtotal: '.(Rupiah::formatWithPrefix($purchaseOrder->subtotal) ?? 'Rp 0'));
        $section->addText('Tax: '.(Rupiah::formatWithPrefix($purchaseOrder->tax_amount) ?? 'Rp 0'));
        $section->addText('Discount: '.(Rupiah::formatWithPrefix($purchaseOrder->discount_amount) ?? 'Rp 0'));
        $section->addText('Total: '.(Rupiah::formatWithPrefix($purchaseOrder->total_amount) ?? 'Rp 0'), ['bold' => true]);
        $section->addText('Terms & Conditions: '.($purchaseOrder->terms_and_conditions ?? '-'));
        $section->addText('Notes: '.($purchaseOrder->notes ?? '-'));

        $path = tempnam(sys_get_temp_dir(), 'purchase-order-');

        if ($path === false) {
            throw new \RuntimeException('Unable to create the Purchase Order document.');
        }

        try {
            IOFactory::createWriter($document, 'Word2007')->save($path);

            return $path;
        } catch (\Throwable $exception) {
            @unlink($path);

            throw $exception;
        }
    }
}
