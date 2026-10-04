import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { connectFeedback, focusIngredientReview, focusMatchOutcome } from '../../resources/js/feedback.js';

test('field errors set invalid and preserve existing descriptions', () => {
    const attributes = new Map([['aria-describedby', 'hint']]);
    const control = {
        setAttribute: (name, value) => attributes.set(name, value),
        getAttribute: name => attributes.get(name) ?? null,
        removeAttribute: name => attributes.delete(name),
    };
    const group = { matches: () => false, querySelectorAll: () => [control] };
    const previous = {
        matches: () => false,
        querySelector: () => control,
        querySelectorAll: () => [control],
    };
    const error = { id: '', parentElement: group, previousElementSibling: previous, getAttribute: () => null };
    const root = { querySelectorAll: selector => selector === '[data-feedback-error]' ? [error] : [] };
    connectFeedback(root);
    assert.equal(attributes.get('aria-invalid'), 'true');
    assert.equal(attributes.get('aria-describedby'), `hint ${error.id}`);
    connectFeedback(root);
    assert.equal(attributes.get('aria-describedby'), `hint ${error.id}`);
    root.querySelectorAll = () => [];
    connectFeedback(root);
    assert.equal(attributes.has('aria-invalid'), false);
    assert.equal(attributes.get('aria-describedby'), 'hint');
});

test('new validation summary receives focus once', () => {
    let focused = 0;
    const summary = {
        focus: () => focused++,
        scrollIntoView: () => {},
    };
    const root = { querySelectorAll: selector => selector === '[data-validation-summary]' ? [summary] : [] };
    connectFeedback(root);
    connectFeedback(root);
    assert.equal(focused, 1);
});

test('feedback transitions honor reduced motion', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(css, /prefers-reduced-motion: reduce/);
    assert.match(css, /transition-duration: 0\.01ms !important/);
});

test('explicit field mapping chooses barcode over scanner controls', () => {
    const values = new Map();
    const barcode = {
        id: '',
        name: '',
        getAttributeNames: () => ['wire:model.defer'],
        getAttribute: name => name === 'wire:model.defer' ? 'barcode' : null,
        setAttribute: (name, value) => values.set(name, value),
        removeAttribute: () => {},
    };
    const scanner = {
        id: 'scanner',
        name: '',
        getAttributeNames: () => [],
        getAttribute: () => null,
        setAttribute: () => { throw new Error('scanner must remain unchanged'); },
    };
    const group = { matches: () => false, querySelectorAll: () => [barcode, scanner] };
    const previous = { matches: () => false, querySelector: () => scanner, querySelectorAll: () => [scanner] };
    const error = { id: '', parentElement: group, previousElementSibling: previous, getAttribute: () => 'barcode' };
    connectFeedback({ querySelectorAll: selector => selector === '[data-feedback-error]' ? [error] : [] });
    assert.equal(values.get('aria-invalid'), 'true');
});

test('review navigation focuses the named ingredient without changing inputs', () => {
    const calls = [];
    const target = {
        focus: options => calls.push(['focus', options]),
        scrollIntoView: options => calls.push(['scroll', options]),
    };
    const root = { getElementById: id => id === 'ingredient-line-2' ? { querySelector: () => target } : null };
    assert.equal(focusIngredientReview('#ingredient-line-2', root), true);
    assert.deepEqual(calls, [['focus', { preventScroll: true }], ['scroll', { block: 'nearest', behavior: 'auto' }]]);
    assert.equal(focusIngredientReview('#ingredient-line-99', root), false);
    assert.equal(focusIngredientReview('#unrelated', root), false);
    assert.equal(calls.length, 2);
});

test('match outcomes focus the persistent status, while validation retains summary focus', () => {
    let focused = 0;
    const root = { getElementById: () => ({ focus: () => focused++ }), querySelector: () => null };
    focusMatchOutcome('ingredient-review-1', root);
    assert.equal(focused, 1);
    root.querySelector = () => ({});
    focusMatchOutcome('ingredient-review-1', root);
    assert.equal(focused, 1);
});
