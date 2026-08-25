import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
    parseEnvironment,
    validateDevelopmentEnvironment,
} from '../../scripts/validate-development-environment.mjs';

const [environmentText, composeText] = await Promise.all([
    readFile(new URL('../../.env.example', import.meta.url), 'utf8'),
    readFile(new URL('../../docker-compose.yml', import.meta.url), 'utf8'),
]);

test('the committed development environment is parseable and aligned with Compose', () => {
    assert.doesNotThrow(() => validateDevelopmentEnvironment(environmentText, composeText));
});

test('malformed and duplicate environment values are rejected', () => {
    assert.throws(
        () => parseEnvironment('APP_ENV=local\nnot valid\n'),
        /line 2 is malformed/u,
    );
    assert.throws(
        () => parseEnvironment('APP_ENV=local\nAPP_ENV=testing\n'),
        /defines APP_ENV more than once/u,
    );
});

test('important environment and service drift is rejected', () => {
    assert.throws(
        () => validateDevelopmentEnvironment(
            environmentText.replace('DB_HOST=mysql', 'DB_HOST=127.0.0.1'),
            composeText,
        ),
        /DB_HOST must be "mysql"/u,
    );
    assert.throws(
        () => validateDevelopmentEnvironment(
            environmentText,
            composeText.replace(/^    mailpit:\s*$/mu, '    local-mail:'),
        ),
        /MAIL_HOST references missing Compose service "mailpit"/u,
    );
});
