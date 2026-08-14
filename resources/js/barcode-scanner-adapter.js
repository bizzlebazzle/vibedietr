import { BrowserMultiFormatReader } from '@zxing/library';

const messages = {
    'permission-denied': 'Camera access was denied. Allow camera access in your browser settings, or enter the product manually.',
    'no-camera': 'No camera is available. You can enter the product manually.',
    unsupported: 'Camera scanning is not supported in this browser. You can enter the product manually.',
    'initialization-failed': 'The barcode scanner could not be initialized. You can enter the product manually.',
    'camera-unavailable': 'The selected camera is unavailable. Choose another camera or enter the product manually.',
    'scan-failed': 'That scan did not contain a usable barcode. Try again or enter the product manually.',
};

export function scannerMessage(code) {
    return messages[code] ?? null;
}

export function normalizeScannedBarcode(result) {
    const value = result?.getText?.() ?? result?.text;

    if (typeof value !== 'string') return null;

    const barcode = value.trim();

    // Match the current server lookup boundary while excluding control data.
    if (barcode === '' || barcode.length > 64 || /[\u0000-\u001f\u007f]/.test(barcode)) {
        return null;
    }

    return barcode;
}

function classifyCameraError(error) {
    if (['NotAllowedError', 'PermissionDeniedError', 'SecurityError'].includes(error?.name)) {
        return 'permission-denied';
    }

    if (['NotFoundError', 'DevicesNotFoundError'].includes(error?.name)) {
        return 'no-camera';
    }

    return 'camera-unavailable';
}

export class BarcodeScannerAdapter {
    constructor({
        video,
        facing = 'environment',
        mediaDevices = globalThis.navigator?.mediaDevices,
        secureContext = globalThis.isSecureContext === true,
        Reader = BrowserMultiFormatReader,
        onResult = () => {},
        onStateChange = () => {},
    }) {
        this.video = video;
        this.facing = facing;
        this.mediaDevices = mediaDevices;
        this.secureContext = secureContext;
        this.Reader = Reader;
        this.onResult = onResult;
        this.onStateChange = onStateChange;

        this.reader = null;
        this.devices = [];
        this.selectedDeviceId = null;
        this.activeDeviceId = null;
        this.running = false;
        this.starting = false;
        this.destroyed = false;
        this.errorCode = null;
        this.acceptedResult = false;
        this.lifecycle = 0;
    }

    snapshot() {
        return {
            running: this.running,
            starting: this.starting,
            devices: [...this.devices],
            selectedDeviceId: this.selectedDeviceId,
            activeDeviceId: this.activeDeviceId,
            errorCode: this.errorCode,
            error: scannerMessage(this.errorCode),
        };
    }

    publish() {
        this.onStateChange(this.snapshot());
    }

    setError(code) {
        this.errorCode = code;
        this.publish();
    }

    isSupported() {
        return this.secureContext &&
            typeof this.mediaDevices?.getUserMedia === 'function' &&
            typeof this.mediaDevices?.enumerateDevices === 'function';
    }

    async initialize() {
        if (this.destroyed) return false;

        if (!this.isSupported()) {
            this.setError('unsupported');
            return false;
        }

        try {
            this.reader ??= new this.Reader();
        } catch {
            this.setError('initialization-failed');
            return false;
        }

        await this.refreshDevices();

        return true;
    }

    async refreshDevices() {
        if (!this.isSupported()) {
            this.devices = [];
            this.setError('unsupported');
            return false;
        }

        try {
            const devices = await this.mediaDevices.enumerateDevices();
            this.devices = devices.filter((device) => device.kind === 'videoinput');

            if (!this.devices.length) {
                this.selectedDeviceId = null;
                this.setError('no-camera');
                return false;
            }

            if (!this.devices.some((device) => device.deviceId === this.selectedDeviceId)) {
                const preferred = this.devices.find((device) =>
                    this.facing === 'environment' && /back|rear|environment/i.test(device.label)
                );
                this.selectedDeviceId = (preferred ?? this.devices[0]).deviceId;
            }

            if (this.errorCode === 'no-camera') this.errorCode = null;
            this.publish();
            return true;
        } catch {
            this.devices = [];
            this.selectedDeviceId = null;
            this.setError('camera-unavailable');
            return false;
        }
    }

    async start() {
        if (this.destroyed || this.running || this.starting) return false;

        if (!this.reader && !await this.initialize()) return false;

        this.starting = true;
        this.running = true;
        this.acceptedResult = false;
        this.errorCode = null;
        const lifecycle = ++this.lifecycle;
        this.publish();

        const onDecode = (result) => {
            if (!result || lifecycle !== this.lifecycle || this.acceptedResult) return;

            const barcode = normalizeScannedBarcode(result);

            if (barcode === null) {
                this.setError('scan-failed');
                return;
            }

            this.acceptedResult = true;
            const format = result.getBarcodeFormat?.() ?? result.format ?? '';
            this.stop();
            this.onResult({ text: barcode, format: String(format) });
        };

        try {
            if (this.selectedDeviceId) {
                await this.reader.decodeFromVideoDevice(this.selectedDeviceId, this.video, onDecode);
            } else {
                await this.reader.decodeFromConstraints(
                    { video: { facingMode: { ideal: this.facing } } },
                    this.video,
                    onDecode,
                );
            }

            if (lifecycle !== this.lifecycle || this.destroyed) {
                this.releaseCamera();
                return false;
            }

            this.activeDeviceId = this.selectedDeviceId;
            this.starting = false;
            this.running = true;
            this.publish();
            await this.refreshDevices();
            return true;
        } catch (error) {
            if (lifecycle !== this.lifecycle || this.destroyed) {
                this.releaseCamera();
                return false;
            }

            this.releaseCamera();
            this.starting = false;
            this.running = false;
            this.activeDeviceId = null;
            this.setError(classifyCameraError(error));
            return false;
        }
    }

    releaseCamera() {
        try {
            this.reader?.reset?.();
        } catch {
            // Cleanup must remain safe even if the vendor reader is already reset.
        }

        const stream = this.video?.srcObject;
        for (const track of stream?.getTracks?.() ?? []) track.stop();
        if (this.video && stream) this.video.srcObject = null;
    }

    stop() {
        ++this.lifecycle;
        this.releaseCamera();
        this.starting = false;
        this.running = false;
        this.activeDeviceId = null;
        this.publish();
    }

    async restart() {
        this.stop();
        return this.start();
    }

    async switchDevice(deviceId) {
        if (!this.devices.some((device) => device.deviceId === deviceId)) {
            this.setError('camera-unavailable');
            return false;
        }

        this.selectedDeviceId = deviceId;
        this.stop();
        return this.start();
    }

    destroy() {
        if (this.destroyed) return;

        this.stop();
        this.destroyed = true;
        this.onResult = () => {};
        this.onStateChange = () => {};
    }
}
