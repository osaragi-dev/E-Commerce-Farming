import test from 'node:test';
import assert from 'node:assert/strict';
import { loadPreferences, savePreferences, rawbtUri } from '../resources/js/printer-preferences.js';

function storage() {
    const data = new Map();
    return { getItem: (key) => data.get(key) ?? null, setItem: (key, value) => data.set(key, value) };
}

test('new or corrupt settings use the browser dialog', () => {
    const local = storage();
    assert.deepEqual(loadPreferences(local, 'USB'), { method: 'browser', printerName: 'USB' });
    local.setItem('farming.printer.v1', 'invalid');
    assert.equal(loadPreferences(local).method, 'browser');
});

test('each device retains its own print method and QZ printer', () => {
    const laptop = storage();
    const android = storage();
    savePreferences(laptop, { method: 'qz', printerName: '  USB printer  ' });
    savePreferences(android, { method: 'rawbt', printerName: '' });
    assert.deepEqual(loadPreferences(laptop), { method: 'qz', printerName: 'USB printer' });
    assert.equal(loadPreferences(android).method, 'rawbt');
});

test('invalid QZ printer names and methods cannot be saved', () => {
    for (const printerName of ['', 'USB\ncommand', 'a'.repeat(151)]) {
        assert.throws(() => savePreferences(storage(), { method: 'qz', printerName }));
    }
    assert.throws(() => savePreferences(storage(), { method: 'unknown', printerName: 'USB' }));
});

test('RawBT protocol preserves binary ESC POS data and rejects injected URLs', () => {
    const bytes = Buffer.from([27, 64, 10, 29, 86, 0]);
    assert.equal(rawbtUri(bytes.toString('base64')), 'rawbt:base64,G0AKHVYA');
    assert.throws(() => rawbtUri('https://other-site/'));
    assert.throws(() => rawbtUri('abc#Intent;'));
});
