import test from 'node:test';
import assert from 'node:assert/strict';
import { posCart } from '../resources/js/pos-cart.js';

const variant = { id: 'a', name: 'Basil', variant: 'Pack', price: '25000.00', stock: 3 };

test('single variants add locally and repeated additions merge with exact totals', () => {
    const cart = posCart();
    cart.choose([variant]);
    cart.choose([variant]);
    assert.equal(cart.lines.length, 1);
    assert.equal(cart.count, 2);
    assert.equal(cart.subtotal, 50000);
    cart.quantity('a', 3);
    assert.equal(cart.subtotal, 75000);
    cart.remove('a');
    assert.equal(cart.count, 0);
});

test('multiple variants require selection and unavailable variants cannot be added', () => {
    const cart = posCart();
    cart.choose([variant, { ...variant, id: 'b', stock: 0 }]);
    assert.equal(cart.choosing, true);
    assert.equal(cart.count, 0);
    cart.add(cart.variants[1]);
    assert.equal(cart.count, 0);
    cart.add(cart.variants[0]);
    assert.equal(cart.choosing, false);
    assert.equal(cart.count, 1);
});

test('invalid quantities and stock limits preserve the previous quantity', () => {
    const cart = posCart();
    cart.add(variant);
    for (const value of [0, -1, 1.0001, '', 'invalid', 4]) {
        cart.quantity('a', value);
        assert.equal(cart.count, 1);
        assert.ok(cart.error);
    }
    cart.quantity('a', 3);
    cart.add(variant);
    assert.equal(cart.count, 3);
});

test('prices remain those reviewed until the server requests reconfirmation', () => {
    const cart = posCart();
    cart.add(variant);
    cart.add({ ...variant, price: '30000.00' });
    assert.equal(cart.subtotal, 50000);
    cart.updatePrices({ a: '30000.00' });
    assert.equal(cart.subtotal, 60000);
    cart.clear();
    assert.equal(cart.subtotal, 0);
});

test('checkout sends only identifiers quantities and reviewed prices and blocks double clicks', async () => {
    const cart = posCart();
    cart.add(variant);
    let resolve;
    const calls = [];
    const wire = { openCheckout(items) { calls.push(items); return new Promise(done => { resolve = done; }); } };
    const pending = cart.checkout(wire);
    await cart.checkout(wire);
    cart.add(variant);
    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0], [{ id: 'a', quantity: 1, price: '25000.00', price_quantity: '1' }]);
    assert.equal(cart.count, 1);
    resolve();
    await pending;
    assert.equal(cart.busy, false);
    assert.equal(cart.count, 1);
});

test('failed checkout preserves the cart and supports retry', async () => {
    const cart = posCart();
    cart.add(variant);
    await cart.checkout({ openCheckout() { throw new Error('Offline'); } });
    assert.equal(cart.count, 1);
    assert.equal(cart.busy, false);
    assert.ok(cart.error);
    await cart.checkout({ async openCheckout() {} });
    assert.equal(cart.error, '');
});


test('decimal quantities use the price basis and round each line half up', () => {
    const cart = posCart();
    cart.add({ ...variant, price: '20000.00', price_quantity: '1.500' });
    cart.quantity('a', '1,5');
    assert.equal(cart.subtotal, 20000);
    cart.quantity('a', '1.6');
    assert.equal(cart.subtotal, 21333.33);
    cart.quantity('a', '0.125');
    assert.equal(cart.subtotal, 1666.67);
    cart.updatePrices({ a: '18000.00' }, { a: '1.500' });
    assert.equal(cart.subtotal, 1500);
    cart.updatePrices({ a: '18000.00' }, { a: '2.000' });
    assert.equal(cart.subtotal, 1125);
});


test('remaining fractional stock can be selected and its default quantity corrected', () => {
    const cart = posCart();
    cart.add({ ...variant, stock: '0.125' });
    assert.equal(cart.lines.length, 1);
    assert.ok(cart.error);
    cart.quantity('a', '0.125');
    assert.equal(cart.error, '');
    assert.equal(cart.subtotal, 3125);
});
