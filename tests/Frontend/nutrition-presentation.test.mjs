import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import colors from 'tailwindcss/colors.js';

function luminance(hex) {
    const channels = hex.slice(1).match(/../g).map(channel => parseInt(channel, 16) / 255);
    return channels.map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
        .reduce((sum, channel, i) => sum + channel * [0.2126, 0.7152, 0.0722][i], 0);
}

function contrast(a, b) {
    const values = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (values[0] + 0.05) / (values[1] + 0.05);
}

test('warning text, correction links, and focus outlines meet contrast in both themes', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const summary = readFileSync(new URL('../../resources/views/components/recipe-attention.blade.php', import.meta.url), 'utf8');
    assert.match(css, /bg-amber-50.*text-amber-900.*dark:border-amber-400.*dark:bg-amber-950.*dark:text-amber-100/);
    assert.match(css, /outline-blue-700 dark:outline-blue-300/);
    assert.match(css, /bg-red-50.*text-red-900.*dark:border-red-400.*dark:bg-red-950.*dark:text-red-100/);
    assert.match(summary, /text-blue-800 underline dark:text-blue-200/);
    for (const [background, foreground, link, outline] of [
        [colors.amber[50], colors.amber[900], colors.blue[800], colors.blue[700]],
        [colors.amber[950], colors.amber[100], colors.blue[200], colors.blue[300]],
        [colors.red[50], colors.red[900], colors.blue[800], colors.blue[700]],
        [colors.red[950], colors.red[100], colors.blue[200], colors.blue[300]],
    ]) {
        assert.ok(contrast(background, foreground) >= 4.5);
        assert.ok(contrast(background, link) >= 4.5);
        assert.ok(contrast(background, outline) >= 3);
    }
});

test('review discovery uses native keyboard controls and persistent non-color status', () => {
    const summary = readFileSync(new URL('../../resources/views/components/recipe-attention.blade.php', import.meta.url), 'utf8');
    const status = readFileSync(new URL('../../resources/views/components/recipe-match-status.blade.php', import.meta.url), 'utf8');
    assert.match(summary, /<details/);
    assert.match(summary, /<summary[^>]*min-h-11/);
    assert.match(summary, /<a href=.*data-review-link/);
    assert.match(status, /tabindex="-1" data-ingredient-review/);
    assert.doesNotMatch(summary + status, /aria-live|role="alert"|title=/);
    assert.match(status, /weaker matching evidence/);
});
