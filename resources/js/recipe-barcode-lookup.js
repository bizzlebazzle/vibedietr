export function recipeBarcodeLookup({ lineId }) {
    return {
        lineId,
        code: '',
        scanning: false,
        pending: false,
        failure: '',
        scanHandler: null,
        openingHandler: null,
        navigationHandler: null,

        init() {
            this.scanHandler = event => {
                if (!this.scanning || this.pending) return;
                this.code = event.detail?.text || '';
                this.closeScanner();
                this.$refs.barcodeInput?.focus();
                void this.lookup();
            };
            this.openingHandler = event => {
                if (event.detail.lineId !== this.lineId) this.closeScanner();
            };
            this.navigationHandler = () => this.closeScanner();
            window.addEventListener(this.scanEvent, this.scanHandler);
            window.addEventListener('recipe-scanner-opening', this.openingHandler);
            document.addEventListener('livewire:navigating', this.navigationHandler);
        },

        get scanEvent() {
            return 'recipe-barcode-scanned-' + this.lineId;
        },

        openScanner() {
            if (this.pending) return;
            window.dispatchEvent(new CustomEvent('recipe-scanner-opening', { detail: { lineId: this.lineId } }));
            this.scanning = true;
        },

        closeScanner() {
            // Stop immediately; Alpine then destroys the scanner inside x-if.
            this.$refs.scanner?.querySelector('[data-barcode-scanner]')?.dispatchEvent(new CustomEvent('barcode-scanner-stop', { bubbles: false }));
            this.scanning = false;
        },

        async lookup() {
            if (this.pending) return;
            this.pending = true;
            this.failure = '';
            this.closeScanner();
            try {
                await this.$wire.lookupIngredientBarcode(this.lineId, this.code);
            } catch {
                this.failure = 'Lookup could not finish. Your recipe edits have been kept. Try again.';
            } finally {
                this.pending = false;
            }
        },

        destroy() {
            this.closeScanner();
            window.removeEventListener(this.scanEvent, this.scanHandler);
            window.removeEventListener('recipe-scanner-opening', this.openingHandler);
            document.removeEventListener('livewire:navigating', this.navigationHandler);
        },
    };
}

function register() {
    if (!window.Alpine || window.__recipeBarcodeLookupRegistered) return;
    window.__recipeBarcodeLookupRegistered = true;
    window.Alpine.data('recipeBarcodeLookup', recipeBarcodeLookup);
}

if (typeof window !== 'undefined') {
    if (window.Alpine) register();
    document.addEventListener('alpine:init', register);
}
