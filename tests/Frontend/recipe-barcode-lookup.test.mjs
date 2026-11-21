import assert from 'node:assert/strict';
import test from 'node:test';
import { recipeBarcodeLookup } from '../../resources/js/recipe-barcode-lookup.js';

test('scans are scoped to the active saved line and duplicate in-flight results are ignored', async () => {
    globalThis.window = new EventTarget();
    globalThis.document = new EventTarget();
    let finish;
    const calls = [];
    const make = lineId => Object.assign(recipeBarcodeLookup({ lineId }), {
        $refs: {},
        $wire: { lookupIngredientBarcode: (...args) => {
            calls.push(args);
            return new Promise(resolve => { finish = resolve; });
        } },
    });
    const first = make(10), second = make(20);
    first.init(); second.init();
    first.openScanner();
    second.openScanner();
    assert.equal(first.scanning, false);
    assert.equal(second.scanning, true);
    window.dispatchEvent(new CustomEvent(first.scanEvent, { detail: { text: 'wrong' } }));
    assert.equal(calls.length, 0);
    window.dispatchEvent(new CustomEvent(second.scanEvent, { detail: { text: '0012345678905' } }));
    window.dispatchEvent(new CustomEvent(second.scanEvent, { detail: { text: 'duplicate' } }));
    assert.deepEqual(calls, [[20, '0012345678905']]);
    assert.equal(second.scanning, false);
    await second.lookup();
    assert.equal(calls.length, 1);
    finish();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(second.pending, false);
    first.destroy(); second.destroy();
});

test('closing, navigating, and destruction stop the camera and remove listeners', () => {
    globalThis.window = new EventTarget();
    globalThis.document = new EventTarget();
    const scanner = new EventTarget();
    let stops = 0;
    scanner.addEventListener('barcode-scanner-stop', () => stops++);
    const row = Object.assign(recipeBarcodeLookup({ lineId: 30 }), {
        $refs: { scanner: { querySelector: () => scanner } },
    });
    row.init(); row.openScanner();
    document.dispatchEvent(new Event('livewire:navigating'));
    assert.equal(row.scanning, false);
    assert.equal(stops, 1);
    row.openScanner(); row.destroy();
    assert.equal(stops, 2);
    row.scanning = true;
    document.dispatchEvent(new Event('livewire:navigating'));
    assert.equal(row.scanning, true);
});

test('transport failure keeps typed code and restores retry controls', async () => {
    const row = Object.assign(recipeBarcodeLookup({ lineId: 40 }), {
        $refs: {}, $wire: { lookupIngredientBarcode: async () => { throw new Error('offline'); } },
    });
    row.code = '0012345678905';
    await row.lookup();
    assert.equal(row.code, '0012345678905');
    assert.equal(row.pending, false);
    assert.match(row.failure, /edits have been kept/);
});
