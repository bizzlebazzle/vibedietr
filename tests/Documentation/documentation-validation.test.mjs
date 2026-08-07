import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const repositoryRoot = path.resolve(import.meta.dirname, '..', '..');
const validator = path.join(repositoryRoot, 'scripts/docs/validate-decisions.mjs');
const linkChecker = path.join(repositoryRoot, 'node_modules/.bin/markdown-link-check');
const linkConfig = path.join(repositoryRoot, '.markdown-link-check.json');

const decisionEntry = `## DEC-001 \u2014 Test decision

- **Question requiring resolution:** What should the fixture decide?
- **Why it matters:** It proves validation behavior.
- **Status:** Open.
- **Owner:** Product owner.
- **Alternatives:** First option; second option.
- **Existing constraints from \u0060PRODUCT_SPEC.md\u0060:** Preserve the fixture convention.
- **Backlog relationships:** Blocked: FND-01.
- **Resolution condition:** The test owner chooses an option.
- **Final decision and rationale:** Unresolved.
`;

const validDecisions = `# Product decision register

## Purpose and use

Fixture register.

## Register summary

| ID | Title | Status | Owner |
| --- | --- | --- | --- |
| DEC-001 | Test decision | Open | Product owner |

` + decisionEntry + `
## Manual validation checklist

- Fixture only.
`;

const validProductSpec = `# Product specification

## Deferred design and policy decisions

- A test choice remains unresolved.
  Decision: DEC-001.

## Future scope

Nothing else.
`;

const validRoadmap = `# Product roadmap

### FND-01 \u2014 P0 \u2014 Test item

- **Outcome:** Support the fixture.
`;

async function createFixture(overrides = {}) {
    const directory = await mkdtemp(path.join(tmpdir(), 'vibedietr-docs-'));
    const paths = {
        decisions: path.join(directory, 'DECISIONS.md'),
        productSpec: path.join(directory, 'PRODUCT_SPEC.md'),
        roadmap: path.join(directory, 'ROADMAP.md'),
        identities: path.join(directory, 'decision-identities.json'),
    };

    await Promise.all([
        writeFile(paths.decisions, overrides.decisions ?? validDecisions),
        writeFile(paths.productSpec, overrides.productSpec ?? validProductSpec),
        writeFile(paths.roadmap, overrides.roadmap ?? validRoadmap),
        writeFile(
            paths.identities,
            JSON.stringify(overrides.identities ?? { 'DEC-001': 'Test decision' }),
        ),
    ]);

    return { directory, paths };
}

function runValidator(paths) {
    return spawnSync(
        process.execPath,
        [
            validator,
            '--decisions',
            paths.decisions,
            '--product-spec',
            paths.productSpec,
            '--roadmap',
            paths.roadmap,
            '--identities',
            paths.identities,
        ],
        { encoding: 'utf8' },
    );
}

test('valid documentation succeeds', async (context) => {
    const fixture = await createFixture();
    context.after(() => rm(fixture.directory, { recursive: true, force: true }));

    const result = runValidator(fixture.paths);

    assert.equal(result.status, 0, result.stderr);
});

test('duplicate decision ID fails with an actionable message', async (context) => {
    const duplicate = validDecisions.replace(
        '## Manual validation checklist',
        decisionEntry + '\n## Manual validation checklist',
    );
    const fixture = await createFixture({ decisions: duplicate });
    context.after(() => rm(fixture.directory, { recursive: true, force: true }));

    const result = runValidator(fixture.paths);

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /duplicate decision ID DEC-001/);
});

test('missing required field fails with an actionable message', async (context) => {
    const decisions = validDecisions.replace('- **Owner:** Product owner.\n', '');
    const fixture = await createFixture({ decisions });
    context.after(() => rm(fixture.directory, { recursive: true, force: true }));

    const result = runValidator(fixture.paths);

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /DEC-001 is missing required field "Owner"/);
});

test('unregistered deferred decision fails with an actionable message', async (context) => {
    const productSpec = validProductSpec.replace('DEC-001', 'DEC-999');
    const fixture = await createFixture({ productSpec });
    context.after(() => rm(fixture.directory, { recursive: true, force: true }));

    const result = runValidator(fixture.paths);

    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /deferred decision references missing decision DEC-999/);
});

test('broken internal Markdown link fails with an actionable message', async (context) => {
    const directory = await mkdtemp(path.join(tmpdir(), 'vibedietr-links-'));
    const markdown = path.join(directory, 'BROKEN.md');
    context.after(() => rm(directory, { recursive: true, force: true }));
    await writeFile(markdown, '# Broken link fixture\n\n[Missing file](missing.md)\n');

    const result = spawnSync(linkChecker, ['--config', linkConfig, markdown], {
        encoding: 'utf8',
    });

    assert.notEqual(result.status, 0);
    assert.match(result.stdout + '\n' + result.stderr, /missing\.md/);
});
