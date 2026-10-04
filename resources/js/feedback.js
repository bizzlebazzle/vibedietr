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

export function focusIngredientReview(hash, root = document) {
    if (!/^#ingredient-line-\d+$/.test(hash)) return false;
    const line = root.getElementById(hash.slice(1));
    const target = line?.querySelector('[data-ingredient-review]');
    if (!target) return false;
    target.focus({ preventScroll: true });
    target.scrollIntoView({ block: 'nearest', behavior: 'auto' });
    return true;
}

export function focusMatchOutcome(targetId, root = document) {
    const target = root.getElementById(targetId);
    if (root.querySelector('[data-validation-summary]')) return;
    target?.focus({ preventScroll: true });
}

if (typeof document !== 'undefined') {
    const refresh = () => connectFeedback();
    document.addEventListener('DOMContentLoaded', refresh);
    document.addEventListener('livewire:navigated', refresh);
    const reviewHash = () => focusIngredientReview(window.location.hash);
    document.addEventListener('DOMContentLoaded', reviewHash);
    document.addEventListener('livewire:navigated', reviewHash);
    window.addEventListener('hashchange', reviewHash);
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-review-link]');
        if (link && new URL(link.href).pathname === window.location.pathname) {
            requestAnimationFrame(() => focusIngredientReview(new URL(link.href).hash));
        }
    });
    window.addEventListener('ingredient-match-updated', event => {
        requestAnimationFrame(() => focusMatchOutcome(event.detail.target));
    });
    const observer = new MutationObserver(() => queueMicrotask(refresh));
    observer.observe(document.documentElement, { childList: true, subtree: true });
}
