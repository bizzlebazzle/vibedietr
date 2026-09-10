import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
    dependencyIds,
    parseDecisions,
    parseRoadmap,
    resolveTaskContext,
    validateContextSources,
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

- **Outcome:** Add food-
  dependent nutrition behavior.
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
- **Final decision and rationale:** Unresolved.

## DEC-002 \u2014 Measurement policy

- **Status:** Decided.
- **Backlog relationships:** Resolution constrains NUT-01.
- **Final decision and rationale:** Use the approved measurement system.

## Manual validation checklist
`;

async function createFixture(context, roadmapContent = roadmap, decisionsContent = decisions) {
    const directory = await mkdtemp(path.join(tmpdir(), 'vibedietr-context-'));
    const docsDirectory = path.join(directory, 'docs');
    await mkdir(docsDirectory);
    const roadmapPath = path.join(docsDirectory, 'ROADMAP.md');
    const decisionsPath = path.join(docsDirectory, 'DECISIONS.md');

    await Promise.all([
        writeFile(roadmapPath, roadmapContent),
        writeFile(decisionsPath, decisionsContent),
        writeFile(path.join(docsDirectory, 'DOMAIN_MODEL.md'), '# Domain\n\nNUT-01 context.\n'),
    ]);
    context.after(() => rm(directory, { recursive: true, force: true }));

    return { decisionsPath, docsDirectory, roadmapPath };
}

test('resolves task context from current authoritative files', async (context) => {
    const paths = await createFixture(context);
    const result = await resolveTaskContext('NUT-01', paths);

    assert.equal(result.task.outcome, 'Add food-dependent nutrition behavior');
    assert.equal(result.task.status, 'Not recorded');
    assert.equal(result.task.title, 'Nutrition task');
    assert.equal(result.dependencies[0].id, 'FND-01');
    assert.equal(result.dependencies[0].ready, true);
    assert.equal(result.decisions[0].id, 'DEC-001');
    assert.equal(result.decisions[0].relationship, 'Blocked');
    assert.equal(result.decisions[1].relationship, 'Constrained');
    assert.equal(result.blockers[0].id, 'DEC-001');
    assert.deepEqual(
        result.documentReferences.map((reference) => path.basename(reference.path)),
        ['DECISIONS.md', 'DOMAIN_MODEL.md', 'ROADMAP.md'],
    );
});

test('rejects a missing task dependency', async () => {
    const parsedRoadmap = parseRoadmap(
        roadmap.replace('FND-01, DEC-001', 'FND-99, DEC-001'),
    );
    const parsedDecisions = parseDecisions(decisions);
    const errors = await validateContextSources({
        decisions: parsedDecisions,
        roadmap: parsedRoadmap,
    });

    assert.ok(
        errors.some((error) => error.includes('NUT-01 references missing task FND-99')),
    );
});

test('expands explicit dependency ranges in source order', () => {
    assert.deepEqual(
        dependencyIds('DEP-03 through DEP-05, UX-07'),
        ['DEP-03', 'DEP-04', 'DEP-05', 'UX-07'],
    );
});

test('rejects a malformed dependency ID instead of omitting it', async () => {
    const parsedRoadmap = parseRoadmap(
        roadmap.replace('FND-01, DEC-001', 'FND-1, DEC-001'),
    );
    const errors = await validateContextSources({
        decisions: parseDecisions(decisions),
        roadmap: parsedRoadmap,
    });

    assert.ok(
        errors.some((error) =>
            error.includes('NUT-01 has invalid dependency "FND-1"'),
        ),
    );
});

test('rejects dependency cycles', async () => {
    const cyclic = roadmap.replace(
        '- **Dependencies:** None.',
        '- **Dependencies:** NUT-01.',
    );
    const errors = await validateContextSources({
        decisions: parseDecisions(decisions),
        roadmap: parseRoadmap(cyclic),
    });

    assert.ok(errors.some((error) => error.includes('Roadmap dependency cycle')));
});

test('rejects unknown task IDs with an actionable error', async (context) => {
    const paths = await createFixture(context);

    await assert.rejects(
        resolveTaskContext('NUT-99', paths),
        /Unknown roadmap task NUT-99/u,
    );
});
