function loadZXingOnce(cdn) {
    if (window.ZXing) return Promise.resolve(window.ZXing);
    document.__zxingPromise ??= new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = cdn;
        s.async = true;
        s.onload = () => window.ZXing ? resolve(window.ZXing) : reject(new Error('ZXing loaded but not found'));
        s.onerror = () => reject(new Error('Failed to load ZXing'));
        document.head.appendChild(s);
    });
    return document.__zxingPromise;
}

function isSecureContextForCamera() {
    return location.protocol === 'https:' ||
        location.hostname === 'localhost' ||
        location.hostname === '127.0.0.1';
}

function registerBarcodeScanner() {
    if (!window.Alpine) return;
    if (window.__barcodeScannerRegistered) return;
    window.__barcodeScannerRegistered = true;

    window.Alpine.data('barcodeScanner', ({ cdn, facing = 'environment', autostart = false, eventName = 'barcode-scanned' }) => ({
        cdn, facing, autostart, eventName,
        reader: null,
        running: false,
        devices: [],
        selectedDeviceId: null,
        error: null,

        async init() {
            try {
                if (!isSecureContextForCamera()) {
                    this.error = 'Camera requires HTTPS or localhost.';
                    return;
                }
                const ZXing = await loadZXingOnce(this.cdn);
                this.reader = new ZXing.BrowserMultiFormatReader();

                // Preflight permission on init to unlock device labels on iOS/Safari
                await this.ensureCameraPermission();

                await this.refreshDevices();
                if (this.autostart) this.start();
            } catch (e) {
                console.error(e);
                this.error = e.message || 'Failed to initialize scanner';
            }
            this.$root.addEventListener('livewire:navigating', () => this.stop(), { once: true });
        },

        // Ask for permission once, then immediately stop tracks
        async ensureCameraPermission() {
            try {
                if (!navigator.mediaDevices?.getUserMedia) return false;
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: this.facing || 'environment' } }
                });
                // Stop tracks immediately; this only serves to unlock device list/labels
                stream.getTracks().forEach(t => t.stop());
                return true;
            } catch (e) {
                // User might deny; keep error visible
                console.warn('Permission preflight failed:', e);
                return false;
            }
        },

        async refreshDevices() {
            try {
                if (!navigator.mediaDevices?.enumerateDevices) {
                    this.devices = [];
                    return;
                }
                let devices = await navigator.mediaDevices.enumerateDevices();
                devices = devices.filter(d => d.kind === 'videoinput');

                // If empty, try prompting permission, then re-enumerate (iOS)
                if (devices.length === 0) {
                    const ok = await this.ensureCameraPermission();
                    if (ok) {
                        let again = await navigator.mediaDevices.enumerateDevices();
                        devices = again.filter(d => d.kind === 'videoinput');
                    }
                }

                this.devices = devices;

                // Select by label (if available) or first device
                if (!this.selectedDeviceId && this.devices.length) {
                    const env = this.devices.find(d => /back|rear|environment/i.test(d.label));
                    this.selectedDeviceId = (this.facing === 'environment' && env)
                        ? env.deviceId
                        : this.devices[0].deviceId;
                }
            } catch (e) {
                console.error(e);
            }
        },

        async start() {
            if (!this.reader || this.running) return;
            this.running = true;
            this.error = null;

            const onResult = (result, err) => {
                if (result) {
                    const text = result.getText?.() ?? result.text ?? '';
                    const format = result.getBarcodeFormat?.() ?? result.format ?? '';
                    window.dispatchEvent(new CustomEvent(this.eventName, { detail: { text, format } }));
                    this.stop();
                }
            };

            try {
                // Use deviceId if chosen; otherwise prefer facingMode constraints
                if (this.selectedDeviceId) {
                    await this.reader.decodeFromVideoDevice(this.selectedDeviceId, this.$refs.video, onResult);
                } else {
                    await this.reader.decodeFromConstraints(
                        { video: { facingMode: { ideal: this.facing || 'environment' } } },
                        this.$refs.video,
                        onResult
                    );
                }

                // Now that permission is granted, refresh to populate labels/devices
                await this.refreshDevices();
            } catch (e) {
                console.error(e);
                this.error = e.message || 'Failed to start camera';
                this.running = false;
            }
        },

        async stop() {
            try { this.reader?.reset?.(); } catch { }
            const v = this.$refs.video;
            const stream = v?.srcObject;
            if (stream) {
                for (const track of (stream.getTracks?.() ?? [])) track.stop();
                v.srcObject = null;
            }
            this.running = false;
        },

        maybeStart() {
            if (this.autostart && !this.running) this.start();
        },

        async restartWithDevice() {
            const was = this.running;
            if (was) await this.stop();
            await this.start();
        },
    }));
}

if (window.Alpine) registerBarcodeScanner();
document.addEventListener('alpine:init', registerBarcodeScanner);
