function scaled(value, places) {
    const [whole, fraction = ''] = String(value).split('.');
    return BigInt(whole) * (10n ** BigInt(places)) + BigInt(fraction.padEnd(places, '0').slice(0, places));
}

export function posCart() {
    return {
        lines: [],
        variants: [],
        choosing: false,
        busy: false,
        error: '',
        money(value) {
            return 'Rp ' + new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
        },
        get count() { return Number(this.lines.reduce((sum, line) => sum + scaled(line.quantity, 3), 0n)) / 1000; },
        get subtotal() {
            return this.lines.reduce((sum, line) => sum + this.lineCents(line), 0) / 100;
        },
        lineCents(line) {
            const numerator = scaled(line.price, 2) * scaled(line.quantity, 3);
            const denominator = scaled(line.price_quantity ?? 1, 3);
            return Number((numerator * 2n + denominator) / (denominator * 2n));
        },
        priceLabel(line) {
            return this.money(line.price) + ' / ' + Number(line.price_quantity ?? 1) + ' ' + (line.unit ?? '');
        },
        choose(variants) {
            if (this.busy) return;
            if (variants.length === 1) {
                this.add(variants[0]);
            } else {
                this.variants = variants;
                this.choosing = true;
            }
        },
        add(variant) {
            if (this.busy) return;
            const existing = this.lines.find(line => line.id === variant.id);
            const quantity = Number(scaled(existing?.quantity ?? 0, 3) + 1000n) / 1000;
            if (Number(variant.stock) <= 0 || (existing && quantity > variant.stock)) {
                this.error = 'Not enough stock available.';
                return;
            }
            if (existing) {
                existing.quantity = quantity;
                existing.stock = variant.stock;
            } else {
                this.lines.push({ ...variant, quantity });
            }
            this.error = quantity > variant.stock ? 'Enter a quantity no greater than ' + variant.stock + '.' : '';
            this.choosing = false;
        },
        quantity(id, value) {
            if (this.busy) return;
            const line = this.lines.find(line => line.id === id);
            const normalized = String(value).trim().replace(',', '.');
            const quantity = Number(normalized);
            if (!line) return;
            if (!/^\d+(?:\.\d{1,3})?$/.test(normalized) || !Number.isFinite(quantity) || quantity <= 0 || quantity > line.stock) {
                this.error = 'Enter a positive quantity with up to 3 decimal places, no greater than ' + line.stock + '.';
                return;
            }
            line.quantity = quantity;
            this.error = '';
        },
        remove(id) {
            if (this.busy) return;
            this.lines = this.lines.filter(line => line.id !== id);
            this.error = '';
        },
        clear() {
            this.lines = [];
            this.error = '';
        },
        updatePrices(prices, priceQuantities = {}) {
            for (const line of this.lines) {
                if (prices[line.id] !== undefined) line.price = prices[line.id];
                if (priceQuantities[line.id] !== undefined) line.price_quantity = priceQuantities[line.id];
            }
        },
        async checkout(wire) {
            if (this.busy || !this.lines.length) return;
            this.busy = true;
            this.error = '';
            try {
                await wire.openCheckout(this.lines.map(({ id, quantity, price, price_quantity }) => ({ id, quantity, price: String(price), price_quantity: String(price_quantity ?? 1) })));
            } catch {
                this.error = 'Checkout could not be opened. Your cart has been kept. Please try again.';
            } finally {
                this.busy = false;
            }
        },
    };
}

if (typeof window !== 'undefined') {
    window.posCart = posCart;
}
