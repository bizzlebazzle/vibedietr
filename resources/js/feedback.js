let errorId = 0;
const focusedSummaries = new WeakSet();
const markedControls = new Map();

export function connectFeedback(root = document) {
    for (const [control, original] of markedControls) {
        if (original.invalid === null) control.removeAttribute('aria-invalid');
        else control.setAttribute('aria-invalid', original.invalid);
        if (original.describedby === null) control.removeAttribute('aria-describedby');
        else control.setAttribute('aria-describedby', original.describedby);
    }
    markedControls.clear();

    root.querySelectorAll('[data-feedback-error]').forEach(error => {
        if (!error.id) error.id = `feedback-error-${++errorId}`;
        const group = error.parentElement;
        if (!group || group.matches('form, section')) return;
        const allControls = [...group.querySelectorAll('input:not([type="hidden"]), select, textarea')];
        const field = error.getAttribute('data-feedback-for');
        const matched = field ? allControls.filter(control =>
            control.id === field || control.name === field ||
            control.getAttributeNames().some(name => name.startsWith('wire:model') && control.getAttribute(name) === field)
        ) : [];
        const preceding = error.previousElementSibling;
        const source = preceding?.matches('input, select, textarea, fieldset') ? preceding : preceding?.querySelector('input, select, textarea') ? preceding : group;
        const controls = matched.length ? matched : source.matches('input, select, textarea')
            ? [source] : [...source.querySelectorAll('input:not([type="hidden"]), select, textarea')];
        controls.forEach(control => {
            if (!markedControls.has(control)) {
                markedControls.set(control, {
                    invalid: control.getAttribute('aria-invalid'),
                    describedby: control.getAttribute('aria-describedby'),
                });
            }
            control.setAttribute('aria-invalid', 'true');
            const descriptions = new Set((control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
            descriptions.add(error.id);
            control.setAttribute('aria-describedby', [...descriptions].join(' '));
        });
    });

    root.querySelectorAll('[data-validation-summary]').forEach(summary => {
        if (focusedSummaries.has(summary)) return;
        focusedSummaries.add(summary);
        summary.focus({ preventScroll: true });
        summary.scrollIntoView({ block: 'nearest' });
    });
}

if (typeof document !== 'undefined') {
    const refresh = () => connectFeedback();
    document.addEventListener('DOMContentLoaded', refresh);
    document.addEventListener('livewire:navigated', refresh);
    const observer = new MutationObserver(() => queueMicrotask(refresh));
    observer.observe(document.documentElement, { childList: true, subtree: true });
}
