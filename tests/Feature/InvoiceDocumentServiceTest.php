<?php

use App\Services\InvoiceDocumentService;

function invoiceDocumentSnapshot(): array
{
    return [
        'invoice_number' => '1074/FARM/IX/2026', 'invoice_date' => '2026-09-18', 'due_date' => '2026-09-21',
        'customer_name' => 'Customer <script> & Co', 'customer_phone' => null,
        'company_name' => 'PT. AGUNG FARM & GARDEN', 'address' => "First line\nSecond line", 'company_phone' => '08115911191',
        'bank_name' => 'BANK BNI', 'bank_account' => '8888706709', 'bank_holder' => 'AGUNG FARM AND GARDEN',
        'items' => [['description' => 'Eggs & honey <fresh>', 'quantity' => 2, 'unit' => 'box', 'unit_price' => '25000.50', 'subtotal' => '50001.00']],
        'subtotal' => '50001.00', 'shipping_cost' => '10000.00', 'total' => '60001.00',
        'total_words' => 'Enam Puluh Ribu Satu Rupiah',
    ];
}

test('invoice document renders the stored amounts and escapes snapshot text', function (): void {
    $snapshot = invoiceDocumentSnapshot();

    $html = view('invoices.document', ['invoice' => $snapshot])->render();
    $pdf = app(InvoiceDocumentService::class)->pdf($snapshot);

    expect($html)->toContain('25.000,50', '50.001,00', '10.000,00', '60.001,00', '18 September 2026', '21 September 2026', 'Eggs &amp; honey &lt;fresh&gt;')
        ->not->toContain('<script>');
    expect($pdf)->toStartWith('%PDF-');
});

test('invoice word document keeps editable text and valid XML with special characters', function (): void {
    $service = app(InvoiceDocumentService::class);
    $path = $service->docx(invoiceDocumentSnapshot());

    try {
        $archive = new ZipArchive;
        expect($archive->open($path))->toBeTrue();
        $xml = $archive->getFromName('word/document.xml');
        $archive->close();
        $document = new DOMDocument;

        expect($document->loadXML($xml))->toBeTrue();
        expect($document->textContent)->toContain('Customer <script> & Co', 'Eggs & honey <fresh>', '25.000,50', '60.001,00', 'BANK BNI 8888706709');
        expect($document->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'tbl')->length)->toBeGreaterThan(0);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        expect($xpath->query('//w:tblBorders/*')->length)->toBe(0)
            ->and($xpath->query('//w:tcBorders/*[not(@w:val="dotted")]')->length)->toBe(0)
            ->and($xpath->query('//w:tcBorders/*[@w:val="dotted"]')->length)->toBe(18);
    } finally {
        @unlink($path);
    }
});
