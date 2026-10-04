// A full-page POST returns focus to the entry's updated state, unless validation
// has already focused the shared error summary. Native forms work without JS.
export function focusPlanningOutcome(root = document) {
    if (root.querySelector('[data-validation-summary]')) return;
    const id = root.querySelector('[data-planning-focus]')?.getAttribute('data-planning-focus');
    if (!id) return;
    const target = root.getElementById(id);
    target?.focus({ preventScroll: true });
    target?.scrollIntoView({ block: 'nearest', behavior: 'auto' });
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => focusPlanningOutcome());
}
