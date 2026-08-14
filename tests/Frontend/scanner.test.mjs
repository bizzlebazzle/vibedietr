import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
    BarcodeScannerAdapter,
    normalizeScannedBarcode,
    scannerMessage,
} from '../../resources/js/barcode-scanner-adapter.js';

const cameras = [
    { kind: 'videoinput', deviceId: 'front', label: 'Front camera' },
    { kind: 'videoinput', deviceId: 'rear', label: 'Rear camera' },
];

function mediaDevices(devices = cameras) {
    return {
        getUserMedia: async () => ({ getTracks: () => [] }),
        enumerateDevices: async () => devices,
    };
}

function readerDouble({ errorForDevice = null } = {}) {
    const instances = [];

    class Reader {
        constructor() {
            this.decodeCalls = [];
            this.resetCalls = 0;
            this.callback = null;
            instances.push(this);
        }

        async decodeFromVideoDevice(deviceId, video, callback) {
            this.decodeCalls.push({ deviceId, video });
            this.callback = callback;

            const error = errorForDevice?.(deviceId);
            if (error) throw error;
        }

        async decodeFromConstraints(constraints, video, callback) {
            this.decodeCalls.push({ constraints, video });
            this.callback = callback;

            const error = errorForDevice?.(null);
            if (error) throw error;
        }

        reset() {
            this.resetCalls += 1;
        }
    }

    return { Reader, instances };
}

function adapterOptions(overrides = {}) {
    return {
        video: { srcObject: null },
        mediaDevices: mediaDevices(),
        secureContext: true,
        ...overrides,
    };
}

test('ZXing is exactly pinned and scanner runtime sources contain no CDN loader', async () => {
    const packageJson = JSON.parse(await readFile('package.json', 'utf8'));
    const packageLock = JSON.parse(await readFile('package-lock.json', 'utf8'));
    const runtimeFiles = await Promise.all([
        readFile('resources/js/scanner.js', 'utf8'),
        readFile('resources/js/barcode-scanner-adapter.js', 'utf8'),
        readFile('resources/views/components/barcode-scanner.blade.php', 'utf8'),
        readFile('resources/views/livewire/ingredients/form.blade.php', 'utf8'),
    ]);

    assert.equal(packageJson.dependencies['@zxing/library'], '0.20.0');
    assert.equal(packageLock.packages['node_modules/@zxing/library'].version, '0.20.0');
    assert.match(runtimeFiles[1], /from '@zxing\/library'/);
    assert.doesNotMatch(runtimeFiles.join('\n'), /unpkg\.com|jsdelivr|cdnjs|createElement\(['"]script/);
});

test('manual product entry remains present independently of scanner state', async () => {
    const form = await readFile('resources/views/livewire/ingredients/form.blade.php', 'utf8');
    const scanner = await readFile('resources/views/components/barcode-scanner.blade.php', 'utf8');

    assert.match(form, /wire:model\.defer="name"/);
    assert.match(form, /wire:submit="save"/);
    assert.match(scanner, /You can always enter the product manually/);

    for (const code of [
        'permission-denied',
        'no-camera',
        'unsupported',
        'initialization-failed',
        'camera-unavailable',
        'scan-failed',
    ]) {
        assert.match(scannerMessage(code), /manually/);
    }
});

test('unsupported media APIs fail gracefully without constructing a reader', async () => {
    const { Reader, instances } = readerDouble();
    const scanner = new BarcodeScannerAdapter(adapterOptions({
        mediaDevices: {},
        Reader,
    }));

    assert.equal(await scanner.initialize(), false);
    assert.equal(scanner.errorCode, 'unsupported');
    assert.equal(instances.length, 0);
});

test('no-camera enumeration is non-fatal and keeps a safe state', async () => {
    const { Reader } = readerDouble();
    const scanner = new BarcodeScannerAdapter(adapterOptions({
        mediaDevices: mediaDevices([]),
        Reader,
    }));

    assert.equal(await scanner.initialize(), true);
    assert.deepEqual(scanner.devices, []);
    assert.equal(scanner.errorCode, 'no-camera');
    assert.equal(scanner.running, false);
});

test('reader initialization failure is classified without raw exception details', async () => {
    class BrokenReader {
        constructor() {
            throw new Error('private browser detail');
        }
    }

    const scanner = new BarcodeScannerAdapter(adapterOptions({ Reader: BrokenReader }));

    assert.equal(await scanner.initialize(), false);
    assert.equal(scanner.errorCode, 'initialization-failed');
    assert.doesNotMatch(scannerMessage(scanner.errorCode), /private browser detail/);
});

test('permission denial is classified and leaves no running scanner', async () => {
    const denied = Object.assign(new Error('raw permission detail'), { name: 'NotAllowedError' });
    const { Reader } = readerDouble({ errorForDevice: () => denied });
    const scanner = new BarcodeScannerAdapter(adapterOptions({ Reader }));

    await scanner.initialize();

    assert.equal(await scanner.start(), false);
    assert.equal(scanner.errorCode, 'permission-denied');
    assert.equal(scanner.running, false);
    assert.equal(scanner.starting, false);
    assert.doesNotMatch(scannerMessage(scanner.errorCode), /raw permission detail/);
});

test('a successful scan emits one validated barcode and stops scanning', async () => {
    const { Reader, instances } = readerDouble();
    const results = [];
    const scanner = new BarcodeScannerAdapter(adapterOptions({
        Reader,
        onResult: (result) => results.push(result),
    }));

    await scanner.initialize();
    assert.equal(await scanner.start(), true);

    const result = {
        getText: () => ' 0123456789012 ',
        getBarcodeFormat: () => 'EAN_13',
    };
    instances[0].callback(result);
    instances[0].callback(result);

    assert.deepEqual(results, [{ text: '0123456789012', format: 'EAN_13' }]);
    assert.equal(scanner.running, false);
    assert.ok(instances[0].resetCalls >= 1);
});

test('invalid and unexpected decoder results are rejected without dispatch', async () => {
    const { Reader, instances } = readerDouble();
    const results = [];
    const scanner = new BarcodeScannerAdapter(adapterOptions({
        Reader,
        onResult: (result) => results.push(result),
    }));

    await scanner.initialize();
    await scanner.start();
    instances[0].callback({ getText: () => 'bad\u0000barcode' });
    instances[0].callback({ text: 12345 });

    assert.deepEqual(results, []);
    assert.equal(scanner.errorCode, 'scan-failed');
    assert.equal(scanner.running, true);
    assert.equal(normalizeScannedBarcode({ text: 'x'.repeat(65) }), null);
});

test('teardown stops tracks, resets ZXing, and is safe repeatedly', async () => {
    const { Reader, instances } = readerDouble();
    let trackStops = 0;
    const video = { srcObject: null };
    const scanner = new BarcodeScannerAdapter(adapterOptions({ Reader, video }));

    await scanner.initialize();
    await scanner.start();
    video.srcObject = {
        getTracks: () => [{ stop: () => { trackStops += 1; } }],
    };

    scanner.stop();
    scanner.stop();

    assert.equal(trackStops, 1);
    assert.equal(video.srcObject, null);
    assert.equal(scanner.running, false);
    assert.ok(instances[0].resetCalls >= 2);
});

test('destroy models modal closure and prevents a scanner restart', async () => {
    const { Reader, instances } = readerDouble();
    const scanner = new BarcodeScannerAdapter(adapterOptions({ Reader }));

    await scanner.initialize();
    await scanner.start();
    scanner.destroy();
    scanner.destroy();

    assert.equal(scanner.destroyed, true);
    assert.equal(scanner.running, false);
    assert.equal(await scanner.start(), false);
    assert.equal(instances[0].decodeCalls.length, 1);
});

test('restart reuses one reader and does not create duplicate scanner instances', async () => {
    const { Reader, instances } = readerDouble();
    const scanner = new BarcodeScannerAdapter(adapterOptions({ Reader }));

    await scanner.initialize();
    await scanner.start();
    assert.equal(await scanner.restart(), true);

    assert.equal(instances.length, 1);
    assert.equal(instances[0].decodeCalls.length, 2);
    assert.equal(scanner.running, true);
});

test('camera switching releases the old stream and activates the new camera', async () => {
    const { Reader, instances } = readerDouble();
    const scanner = new BarcodeScannerAdapter(adapterOptions({ Reader }));

    await scanner.initialize();
    await scanner.start();
    const resetsBeforeSwitch = instances[0].resetCalls;

    assert.equal(scanner.activeDeviceId, 'rear');
    assert.equal(await scanner.switchDevice('front'), true);
    assert.equal(scanner.activeDeviceId, 'front');
    assert.deepEqual(
        instances[0].decodeCalls.map((call) => call.deviceId),
        ['rear', 'front'],
    );
    assert.ok(instances[0].resetCalls > resetsBeforeSwitch);
});

test('a failed camera switch is recoverable', async () => {
    const unavailable = Object.assign(new Error('device disappeared'), { name: 'OverconstrainedError' });
    const { Reader } = readerDouble({
        errorForDevice: (deviceId) => deviceId === 'front' ? unavailable : null,
    });
    const scanner = new BarcodeScannerAdapter(adapterOptions({ Reader }));

    await scanner.initialize();
    await scanner.start();

    assert.equal(await scanner.switchDevice('front'), false);
    assert.equal(scanner.errorCode, 'camera-unavailable');
    assert.equal(scanner.running, false);
    assert.equal(await scanner.switchDevice('rear'), true);
    assert.equal(scanner.activeDeviceId, 'rear');
});

test('the Alpine integration cleans up on navigation and component destruction', async () => {
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    const previousSecureContext = globalThis.isSecureContext;
    const registered = new Map();
    const listeners = new Map();

    globalThis.window = {
        Alpine: {
            data: (name, factory) => registered.set(name, factory),
        },
        dispatchEvent: () => {},
    };
    globalThis.document = {
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: (name, callback) => {
            if (listeners.get(name) === callback) listeners.delete(name);
        },
    };
    globalThis.isSecureContext = true;

    const navigatorDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: { mediaDevices: mediaDevices() },
    });

    try {
        await import(`../../resources/js/scanner.js?integration=${Date.now()}`);
        const component = registered.get('barcodeScanner')({ autostart: false });
        component.$refs = { video: { srcObject: null } };

        await component.init();
        assert.ok(component.scanner);
        assert.equal(typeof listeners.get('livewire:navigating'), 'function');

        listeners.get('livewire:navigating')();
        assert.equal(component.running, false);

        component.destroy();
        assert.equal(component.scanner, null);
        assert.equal(listeners.has('livewire:navigating'), false);
    } finally {
        globalThis.window = previousWindow;
        globalThis.document = previousDocument;
        globalThis.isSecureContext = previousSecureContext;

        if (navigatorDescriptor) {
            Object.defineProperty(globalThis, 'navigator', navigatorDescriptor);
        } else {
            delete globalThis.navigator;
        }
    }
});
