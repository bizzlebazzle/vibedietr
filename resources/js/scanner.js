import { BarcodeScannerAdapter } from './barcode-scanner-adapter.js';

function registerBarcodeScanner() {
    if (!window.Alpine || window.__barcodeScannerRegistered) return;
    window.__barcodeScannerRegistered = true;

    window.Alpine.data('barcodeScanner', ({ facing = 'environment', autostart = false, eventName = 'barcode-scanned' }) => ({
        facing,
        autostart,
        eventName,
        scanner: null,
        running: false,
        starting: false,
        devices: [],
        selectedDeviceId: null,
        activeDeviceId: null,
        errorCode: null,
        error: null,
        navigationHandler: null,

        sync(state) {
            this.running = state.running;
            this.starting = state.starting;
            this.devices = state.devices;
            this.selectedDeviceId = state.selectedDeviceId;
            this.activeDeviceId = state.activeDeviceId;
            this.errorCode = state.errorCode;
            this.error = state.error;
        },

        async init() {
            this.navigationHandler = () => this.stop();
            document.addEventListener('livewire:navigating', this.navigationHandler);

            this.scanner = new BarcodeScannerAdapter({
                video: this.$refs.video,
                facing: this.facing,
                onStateChange: (state) => this.sync(state),
                onResult: (detail) => window.dispatchEvent(new CustomEvent(this.eventName, { detail })),
            });

            const initialized = await this.scanner.initialize();
            if (initialized && this.autostart) await this.start();
        },

        async start() {
            await this.scanner?.start();
        },

        stop() {
            this.scanner?.stop();
        },

        maybeStart() {
            if (this.autostart && !this.running) void this.start();
        },

        async restartWithDevice() {
            await this.scanner?.switchDevice(this.selectedDeviceId);
        },

        destroy() {
            if (this.navigationHandler) {
                document.removeEventListener('livewire:navigating', this.navigationHandler);
                this.navigationHandler = null;
            }

            this.scanner?.destroy();
            this.scanner = null;
        },
    }));
}

if (window.Alpine) registerBarcodeScanner();
document.addEventListener('alpine:init', registerBarcodeScanner);
