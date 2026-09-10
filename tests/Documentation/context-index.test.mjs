import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
    buildContextIndex,
    checkContextIndex,
} from '../../scripts/docs/context-index.mjs';

import {
    parseDecisions,
    relationshipFor,
} from '../../scripts/docs/task-context.mjs';

const roadmap = `# Product roadmap

## 1. Foundation

### FND-01 \u2014 P0 \u2014 Foundation task

- **Status:** Complete (2026-01-01).
- **Outcome:** Establish the foundation.
- **Dependencies:** None.
- **Acceptance criteria:** The foundation exists.
- **Suggested automated tests:** Foundation tests.
- **Risk:** Low.
- **Estimated size:** Small.

## 2. Nutrition

### NUT-01 \u2014 P1 \u2014 Nutrition task

- **Outcome:** Add nutrition behavior.
- **Dependencies:** FND-01, DEC-001.
- **Acceptance criteria:** Nutrition behavior is deterministic.
- **Suggested automated tests:** Boundary tests.
- **Risk:** High.
- **Estimated size:** Medium.
`;

const decisions = `# Product decision register

## DEC-001 \u2014 Threshold policy

- **Status:** Research required.
- **Backlog relationships:** Blocked: NUT-01. Related: FND-01.
`;

const domain = `# Domain model

## Represented concepts

### Nutrition fact

NUT-01 follows DEC-001 while retaining SHA-256 evidence labels.
`;

async function createFixture(context, overrides = {}) {
    const directory = await mkdtemp(path.join(tmpdir(), 'vibedietr-index-'));
    const docsDirectory = path.join(directory, 'docs');
    await mkdir(docsDirectory);
    const paths = {
        decisionsPath: path.join(docsDirectory, 'DECISIONS.md'),
        domainPath: path.join(docsDirectory, 'DOMAIN_MODEL.md'),
        outputPath: path.join(docsDirectory, 'CONTEXT_INDEX.md'),
        roadmapPath: path.join(docsDirectory, 'ROADMAP.md'),
    };

    await Promise.all([
        writeFile(paths.roadmapPath, overrides.roadmap ?? roadmap),
        writeFile(paths.decisionsPath, overrides.decisions ?? decisions),
        writeFile(paths.domainPath, overrides.domain ?? domain),
    ]);
    context.after(() => rm(directory, { recursive: true, force: true }));

    return paths;
}

test('generates reciprocal task, decision, and domain-section indexes', async (context) => {
    const paths = await createFixture(context);
    const index = await buildContextIndex(paths);

    assert.match(
        index,
        /`NUT-01` - Nutrition task .* `FND-01`, `DEC-001` .* `DEC-001` \(Blocked\) .* Nutrition fact \(line 5\)/u,
    );
    assert.match(
        index,
        /`DEC-001` - Threshold policy .* `FND-01` \(Related\), `NUT-01` \(Blocked\) .* Nutrition fact \(line 5\)/u,
    );
    assert.match(
        index,
        /Nutrition fact \| 5-8 \| `NUT-01` \| `DEC-001`/u,
    );
});

test('classifies separate relationships within one decision sentence', () => {
    const mixed = parseDecisions(`# Decisions

## DEC-002 \u2014 Mixed relationship

- **Status:** Decided.
- **Backlog relationships:** Resolution removes DEC-002 as a blocker for NUT-01 and constrains FND-01.
`).get('DEC-002');

    assert.equal(relationshipFor(mixed, 'NUT-01'), 'Unblocked');
    assert.equal(relationshipFor(mixed, 'FND-01'), 'Constrained');
});

test('detects a stale committed context index', async (context) => {
    const paths = await createFixture(context);
    await writeFile(paths.outputPath, 'stale\n');

    await assert.rejects(
        checkContextIndex(paths),
        /CONTEXT_INDEX\.md is stale/u,
    );

    const expected = await buildContextIndex(paths);
    await writeFile(paths.outputPath, expected);

    assert.equal(await checkContextIndex(paths), expected);
});

test('rejects unknown and malformed project references in domain context', async (context) => {
    const unknownPaths = await createFixture(context, {
        domain: domain.replace('NUT-01 follows', 'NUT-99 follows'),
    });

    await assert.rejects(
        buildContextIndex(unknownPaths),
        /references missing roadmap task NUT-99/u,
    );

    const malformedPaths = await createFixture(context, {
        domain: domain.replace('NUT-01 follows', 'NUT-1 follows'),
    });

    await assert.rejects(
        buildContextIndex(malformedPaths),
        /malformed roadmap or decision reference "NUT-1"/u,
    );
});
