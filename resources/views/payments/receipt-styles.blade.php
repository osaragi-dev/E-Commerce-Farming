    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; background: #eee; font: 12px/1.2 Arial, sans-serif; }
        .toolbar { max-width: 80mm; margin: 20px auto; padding: 0 4mm; }
        button { padding: 10px 16px; border: 1px solid #000; background: #fff; color: #000; cursor: pointer; font: inherit; }
        .receipt { width: 80mm; max-width: 100%; margin: 0 auto; padding: 4mm 4mm 0; background: #fff; overflow-wrap: anywhere; }
        header, footer { text-align: center; }
        .logo { width: 35mm; max-width: 100%; height: auto; filter: grayscale(1); }
        p { margin: 2px 0; }
        dl { margin: 6px 0; }
        dl div { display: flex; justify-content: space-between; gap: 8px; margin: 1px 0; }
        dt { flex-shrink: 0; }
        dd { margin: 0; text-align: right; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; border-top: 1px dashed #000; }
        td { padding: 3px 0; vertical-align: top; }
        .item-details { display: grid; grid-template-columns: minmax(0, 1fr) 28mm; gap: 8px; align-items: start; }
        .item-subtotal { text-align: right; }
        .item-name { font-weight: bold; }
        .totals { border-top: 1px dashed #000; padding-top: 4px; }
        .total { font-weight: bold; border-top: 1px dashed #000; padding-top: 3px; }
        .payment-method { font-weight: bold; }
        footer { border-top: 1px dashed #000; margin-top: 6px; padding-top: 4px; }
        @page { margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .receipt { margin: 0; }
            tr, header, footer, .totals { break-inside: avoid; }
        }
    </style>
