<?php

namespace App\Services;

use App\Filament\Support\Rupiah;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Tab;
use PhpOffice\PhpWord\Style\Table;

class InvoiceDocumentService
{
    /**
     * @param  array{invoice_number: string, invoice_date: string, due_date: string, customer_name: string, customer_phone: ?string, company_name: string, address: ?string, company_phone: ?string, bank_name: ?string, bank_account: ?string, bank_holder: ?string, items: list<array{description: string, quantity: int|float|string, unit: string, unit_price: int|float|string, subtotal: int|float|string}>, subtotal: int|float|string, shipping_cost: int|float|string, total: int|float|string, total_words: string, logo_data_uri?: ?string}  $payload
     */
    public function pdf(array $payload): string
    {
        $pdf = Pdf::setOption('isRemoteEnabled', false)->setOption('fontHeightRatio', 0.85);
        $fonts = [
            'regular' => ['family' => 'Invoice Times', 'weight' => 'normal', 'style' => 'normal'],
            'bold' => ['family' => 'Invoice Times', 'weight' => 'bold', 'style' => 'normal'],
            'italic' => ['family' => 'Invoice Times', 'weight' => 'normal', 'style' => 'italic'],
            'heading' => ['family' => 'Invoice Garamond', 'weight' => 'bold', 'style' => 'normal'],
            'company' => ['family' => 'Invoice Playfair', 'weight' => 'bold', 'style' => 'normal'],
        ];
        $options = $pdf->getDomPDF()->getOptions();
        File::ensureDirectoryExists($options->getFontDir());
        $fontPaths = array_filter(config('invoice.fonts', []), fn (mixed $path): bool => is_string($path) && is_file($path));
        $options->setChroot(array_unique([...$options->getChroot(), ...array_map(dirname(...), $fontPaths)]));
        foreach ($fontPaths as $name => $path) {
            if (isset($fonts[$name])) {
                $pdf->getDomPDF()->getFontMetrics()->registerFont($fonts[$name], $path);
            }
        }

        return $pdf->loadView('invoices.document', ['invoice' => $payload])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', false)
            ->output();
    }

    /** @param array{invoice_number: string, customer_name: string} $payload */
    public function filename(array $payload): string
    {
        return 'invoice-'.(Str::slug($payload['invoice_number']) ?: 'draft')
            .'-'.(Str::slug($payload['customer_name']) ?: 'customer');
    }

    /** @param array<string, mixed> $payload */
    public function docx(array $payload): string
    {
        $document = new PhpWord;
        $document->setDefaultFontName('Times New Roman');
        $document->setDefaultFontSize(9.65);
        $document->setDefaultParagraphStyle(['spaceAfter' => 0, 'spaceBefore' => 0]);
        $section = $document->addSection([
            'paperSize' => 'A4', 'marginTop' => 840, 'marginRight' => 100,
            'marginBottom' => 600, 'marginLeft' => 440,
        ]);
        $tableStyle = ['layout' => Table::LAYOUT_FIXED, 'cellMargin' => 0];
        $header = $section->addTable($tableStyle);
        $header->addRow();
        $company = $header->addCell(6060);
        $logo = $payload['logo_data_uri'] ?? null;
        $logoContents = is_string($logo) && str_starts_with($logo, 'data:image/png;base64,')
            ? base64_decode(substr($logo, 22), true)
            : false;
        $company->addImage($logoContents ?: public_path('images/logo/agung-farm-logo.png'), [
            'width' => 118.67, 'height' => 61.05,
        ]);
        $companyName = $company->addTextRun(['spaceBefore' => 100, 'spaceAfter' => 100]);
        foreach (explode('&', $payload['company_name']) as $index => $part) {
            if ($index > 0) {
                $companyName->addText('&', ['name' => 'Times New Roman', 'bold' => true, 'size' => 10.52]);
            }
            $companyName->addText($part, ['name' => 'Playfair Display', 'bold' => true, 'size' => 10.52]);
        }
        foreach (preg_split('/\R/u', (string) $payload['address']) ?: [] as $line) {
            $company->addText($line, null, ['spaceAfter' => 100]);
        }
        $company->addText('Phone : '.($payload['company_phone'] ?: '-'));
        $meta = $header->addCell(5300);
        $meta->addText('INVOICE', ['name' => 'Garamond', 'size' => 20.17, 'bold' => true], ['spaceAfter' => 140]);
        $metadata = $meta->addTable($tableStyle);
        foreach ([
            'Number' => $payload['invoice_number'],
            'Inv. Date' => CarbonImmutable::parse($payload['invoice_date'])->format('j F Y'),
            'Payment Term' => 'Transfer',
            'Due Date' => CarbonImmutable::parse($payload['due_date'])->format('j F Y'),
            'Currency' => 'IDR',
        ] as $label => $value) {
            $metadata->addRow(364);
            $metadata->addCell(2294)->addText($label);
            $metadata->addCell(256)->addText(':');
            $metadata->addCell(2750)->addText($value);
        }
        $section->addTextBreak(1, null, ['spaceAfter' => 40]);
        $customer = $section->addTable($tableStyle);
        foreach (['Customer' => $payload['customer_name'], 'No. HP' => $payload['customer_phone'] ?: '-'] as $label => $value) {
            $customer->addRow(364);
            $customer->addCell(1322)->addText($label);
            $customer->addCell(176)->addText(':');
            $customer->addCell(9862)->addText($value);
        }
        $section->addTextBreak(1, null, ['spaceAfter' => 240]);
        $items = $section->addTable($tableStyle + ['cellMarginTop' => 84, 'cellMarginBottom' => 84, 'cellMarginLeft' => 100, 'cellMarginRight' => 100]);
        $widths = [490, 4112, 625, 863, 2545, 2725];
        $items->addRow(480, ['tblHeader' => true]);
        foreach (['No.', 'Detail Produk', 'Qty', '', 'Harga Satuan', 'Sub Total'] as $index => $label) {
            $items->addCell($widths[$index], [
                'borderTopSize' => 4, 'borderTopStyle' => 'dotted',
                'borderBottomSize' => 4, 'borderBottomStyle' => 'dotted',
            ])->addText($label, ['bold' => true], ['alignment' => Jc::CENTER]);
        }
        foreach ($payload['items'] as $index => $item) {
            $items->addRow(392, ['cantSplit' => true]);
            $items->addCell($widths[0])->addText((string) ($index + 1), null, ['alignment' => Jc::CENTER]);
            $items->addCell($widths[1])->addText($item['description']);
            $items->addCell($widths[2])->addText((string) $item['quantity'], null, ['alignment' => Jc::CENTER]);
            $items->addCell($widths[3])->addText($item['unit']);
            $priceCell = $items->addCell($widths[4]);
            $this->addWordMoney($priceCell, $item['unit_price'], $widths[4] - 200);
            $priceCell->addText('/ '.($item['price_quantity'] ?? '1').' '.$item['unit'], null, ['alignment' => Jc::RIGHT]);
            $this->addWordMoney($items->addCell($widths[5]), $item['subtotal'], $widths[5] - 200);
        }
        $section->addTextBreak(1, null, ['spaceAfter' => 40]);
        $footer = $section->addTable($tableStyle);
        $footer->addRow(null, ['cantSplit' => true]);
        $words = $footer->addCell(6134, ['cellMarginLeft' => 480, 'cellMarginRight' => 300]);
        $words->addText('Terbilang : '.$payload['total_words'], ['italic' => true]);
        $words->addText('Pembayaran ke Rekening :', ['bold' => true], ['spaceBefore' => 1240, 'spaceAfter' => 260]);
        $words->addText(trim($payload['bank_name'].' '.$payload['bank_account']), ['bold' => true], ['spaceAfter' => 220]);
        $words->addText('a/n '.$payload['bank_holder'], ['bold' => true]);
        $summary = $footer->addCell(5226)->addTable($tableStyle);
        foreach (['Total' => $payload['subtotal'], 'Diskon' => $payload['discount_amount'] ?? null, 'Biaya Kirim' => (float) $payload['shipping_cost'] !== 0.0 ? $payload['shipping_cost'] : null, 'Grand Total' => $payload['total']] as $label => $amount) {
            $summary->addRow(400);
            $summary->addCell(2218)->addText($label, ['bold' => true], ['alignment' => Jc::END]);
            $summary->addCell(280);
            $style = $label === 'Grand Total'
                ? ['borderTopSize' => 4, 'borderTopStyle' => 'dotted', 'borderBottomSize' => 4, 'borderBottomStyle' => 'dotted']
                : [];
            $summary->addCell(400, $style)->addText($amount !== null ? 'Rp' : '', ['bold' => true], ['indentation' => ['left' => 100]]);
            $summary->addCell(2328, $style)->addText($amount !== null ? Rupiah::format($amount) : '', ['bold' => true], [
                'alignment' => Jc::END, 'indentation' => ['right' => 100],
            ]);
        }
        $summary->addRow(60, ['exactHeight' => true]);
        $summary->addCell(2218);
        $summary->addCell(280);
        $summary->addCell(400, ['borderBottomSize' => 4, 'borderBottomStyle' => 'dotted']);
        $summary->addCell(2328, ['borderBottomSize' => 4, 'borderBottomStyle' => 'dotted']);
        $path = tempnam(sys_get_temp_dir(), 'invoice-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create the invoice document.');
        }
        $escapeOutput = Settings::isOutputEscapingEnabled();
        try {
            Settings::setOutputEscapingEnabled(true);
            IOFactory::createWriter($document, 'Word2007')->save($path);

            return $path;
        } catch (\Throwable $exception) {
            @unlink($path);

            throw $exception;
        } finally {
            Settings::setOutputEscapingEnabled($escapeOutput);
        }
    }

    private function addWordMoney(Cell $cell, int|float|string $amount, int $width, bool $bold = false): void
    {
        $cell->addText('Rp'."\t".Rupiah::format($amount), ['bold' => $bold], [
            'tabs' => [new Tab('right', $width)],
        ]);
    }
}
