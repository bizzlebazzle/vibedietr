import { test } from 'node:test';
import assert from 'node:assert/strict';
import { focusPlanningOutcome } from '../../resources/js/planning.js';

test('successful planning returns focus to the affected entry and respects validation focus', () => {
    const calls = [];
    let error = false;
    const root = {
        querySelector: selector => selector === '[data-validation-summary]' ? (error ? {} : null) : { getAttribute: () => 'entry-recipe-1' },
        getElementById: id => {
            assert.equal(id, 'entry-recipe-1');
            return { focus: options => calls.push(options), scrollIntoView: options => calls.push(options) };
        },
    };
    focusPlanningOutcome(root);
    assert.deepEqual(calls, [{ preventScroll: true }, { block: 'nearest', behavior: 'auto' }]);
    error = true;
    focusPlanningOutcome(root);
    assert.equal(calls.length, 2);
});

test('missing or removed entries do not steal focus', () => {
    focusPlanningOutcome({ querySelector: () => null });
    focusPlanningOutcome({ querySelector: selector => selector === '[data-planning-focus]' ? { getAttribute: () => 'entry-item-1' } : null, getElementById: () => null });
});
