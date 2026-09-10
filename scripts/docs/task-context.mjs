import { access, readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const taskPattern = /^[A-Z]{2,4}-\d{2}$/u;
const decisionPattern = /^DEC-\d{3}$/u;
const referencePattern = /\b(?:[A-Z]{2,4}-\d{2}|DEC-\d{3})\b/gu;
const requiredTaskFields = [
    'Outcome',
    'Dependencies',
    'Acceptance criteria',
    'Suggested automated tests',
    'Risk',
    'Estimated size',
];

function withoutPeriod(value) {
    const trimmed = value.trim();

    return trimmed.endsWith('.') ? trimmed.slice(0, -1) : trimmed;
}

export function dependencyIds(value) {
    const expandedRanges = value.replace(
        /\b([A-Z]{2,4})-(\d{2})\s+through\s+\1-(\d{2})\b/gu,
        (match, prefix, first, last) => {
            const start = Number(first);
            const end = Number(last);

            if (end < start) {
                return match;
            }

            return Array.from(
                { length: end - start + 1 },
                (unused, offset) => `${prefix}-${String(start + offset).padStart(2, '0')}`,
            ).join(', ');
        },
    );

    return [...new Set(expandedRanges.match(referencePattern) ?? [])];
}

function parseFields(lines, start, end) {
    const fields = new Map();
    let activeField = null;

    for (let index = start; index < end; index += 1) {
        const match = lines[index].match(/^- \*\*([^*]+):\*\*\s*(.*)$/u);

        if (match) {
            fields.set(match[1], {
                lineNumber: index + 1,
                value: match[2].trim(),
            });
            activeField = match[1];
        } else if (activeField && /^\s{2}\S/u.test(lines[index])) {
            const field = fields.get(activeField);
            const continuation = lines[index].trim();
            const separator = field.value.endsWith('-') ? '' : ' ';

            field.value = `${field.value}${separator}${continuation}`.trim();
        }
    }

    return fields;
}

export function parseRoadmap(content, filename = 'docs/ROADMAP.md') {
    const lines = content.split(/\r?\n/u);
    const structuralHeadings = [];
    let section = '';

    lines.forEach((line, index) => {
        if (/^## /u.test(line)) {
            section = line.replace(/^##\s+/u, '');
            structuralHeadings.push({ index, kind: 'section' });

            return;
        }

        if (!/^### /u.test(line)) {
            return;
        }

        const match = line.match(/^### ([A-Z]{2,4}-\d{2}) \u2014 (P[0-3]) \u2014 (.+)$/u);

        if (!match) {
            throw new Error(
                `${filename}:${index + 1}: task heading must use "### PREFIX-NN -- Pn -- Title"`,
            );
        }

        structuralHeadings.push({
            id: match[1],
            index,
            kind: 'task',
            lineNumber: index + 1,
            priority: match[2],
            section,
            title: match[3],
        });
    });

    const tasks = new Map();

    for (const heading of structuralHeadings.filter(({ kind }) => kind === 'task')) {
        if (tasks.has(heading.id)) {
            throw new Error(
                `${filename}:${heading.lineNumber}: duplicate task ID ${heading.id}`,
            );
        }

        const nextHeading = structuralHeadings.find(({ index }) => index > heading.index);
        const end = nextHeading?.index ?? lines.length;
        const fields = parseFields(lines, heading.index + 1, end);
        const dependencyText = withoutPeriod(fields.get('Dependencies')?.value ?? '');
        const dependencies = dependencyText === 'None' ? [] : dependencyIds(dependencyText);

        tasks.set(heading.id, {
            ...heading,
            dependencies,
            dependencyText,
            fields,
            status: withoutPeriod(fields.get('Status')?.value ?? 'Not recorded'),
        });
    }

    return tasks;
}

export function parseDecisions(content, filename = 'docs/DECISIONS.md') {
    const lines = content.split(/\r?\n/u);
    const headings = [];

    lines.forEach((line, index) => {
        const match = line.match(/^## (DEC-\d{3}) \u2014 (.+)$/u);

        if (match) {
            headings.push({
                id: match[1],
                index,
                lineNumber: index + 1,
                title: match[2],
            });
        }
    });

    const decisions = new Map();

    headings.forEach((heading, headingIndex) => {
        if (decisions.has(heading.id)) {
            throw new Error(
                `${filename}:${heading.lineNumber}: duplicate decision ID ${heading.id}`,
            );
        }

        const checklist = lines.findIndex(
            (line, index) => index > heading.index && line === '## Manual validation checklist',
        );
        const end = headings[headingIndex + 1]?.index
            ?? (checklist === -1 ? lines.length : checklist);
        const fields = parseFields(lines, heading.index + 1, end);

        decisions.set(heading.id, {
            ...heading,
            fields,
            status: withoutPeriod(fields.get('Status')?.value ?? ''),
        });
    });

    return decisions;
}

function relationshipFor(decision, taskId) {
    const relationships = (
        decision.fields.get('Backlog relationships')?.value ?? ''
    ).replaceAll('`', '');
    const sentences = relationships.split(/(?<=[.!?])\s+/u);

    for (const sentence of sentences) {
        if (!(sentence.match(referencePattern) ?? []).includes(taskId)) {
            continue;
        }

        if (/\bunblock(?:s|ed)?\b/iu.test(sentence)) {
            return 'Unblocked';
        }

        if (/\bblock(?:s|ed)?\b/iu.test(sentence)) {
            return 'Blocked';
        }

        if (/\bconstrain(?:s|ed)?\b/iu.test(sentence)) {
            return 'Constrained';
        }

        if (/\brelat(?:e|es|ed)\b/iu.test(sentence)) {
            return 'Related';
        }
    }

    return null;
}

function validateDependencyCycles(tasks, errors) {
    const visited = new Set();
    const visiting = new Set();

    function visit(taskId, trail) {
        if (visiting.has(taskId)) {
            errors.push(`Roadmap dependency cycle: ${[...trail, taskId].join(' -> ')}`);

            return;
        }

        if (visited.has(taskId)) {
            return;
        }

        visiting.add(taskId);
        const task = tasks.get(taskId);

        for (const dependency of task.dependencies.filter((id) => taskPattern.test(id))) {
            if (tasks.has(dependency)) {
                visit(dependency, [...trail, taskId]);
            }
        }

        visiting.delete(taskId);
        visited.add(taskId);
    }

    for (const taskId of tasks.keys()) {
        visit(taskId, []);
    }
}

export async function validateContextSources({
    decisions,
    decisionsPath = 'docs/DECISIONS.md',
    roadmap,
    roadmapPath = 'docs/ROADMAP.md',
}) {
    const errors = [];

    for (const task of roadmap.values()) {
        for (const field of requiredTaskFields) {
            if (!task.fields.get(field)?.value) {
                errors.push(`${roadmapPath}: ${task.id} is missing required field "${field}"`);
            }
        }

        const dependencyLikeTokens = task.dependencyText.match(/\b[A-Z]{2,4}-\d+\b/gu) ?? [];

        for (const token of dependencyLikeTokens) {
            if (!taskPattern.test(token) && !decisionPattern.test(token)) {
                errors.push(
                    `${roadmapPath}: ${task.id} has invalid dependency "${token}"`,
                );
            }
        }

        for (const dependency of task.dependencies) {
            if (taskPattern.test(dependency) && !roadmap.has(dependency)) {
                errors.push(`${roadmapPath}: ${task.id} references missing task ${dependency}`);
            } else if (decisionPattern.test(dependency) && !decisions.has(dependency)) {
                errors.push(
                    `${roadmapPath}: ${task.id} references missing decision ${dependency}`,
                );
            } else if (dependency === task.id) {
                errors.push(`${roadmapPath}: ${task.id} depends on itself`);
            }
        }

        const implementation = task.fields.get('Implementation')?.value ?? '';
        const links = implementation.matchAll(/\[[^\]]+\]\(([^)]+\.md)\)/gu);

        for (const [, link] of links) {
            const target = path.resolve(path.dirname(roadmapPath), link);

            try {
                await access(target);
            } catch {
                errors.push(
                    `${roadmapPath}: ${task.id} links to missing implementation document ${link}`,
                );
            }
        }
    }

    for (const decision of decisions.values()) {
        const relationships = decision.fields.get('Backlog relationships')?.value ?? '';
        const taskReferences = relationships.match(/\b[A-Z]{2,4}-\d{2}\b/gu) ?? [];

        for (const taskId of new Set(taskReferences)) {
            if (!roadmap.has(taskId)) {
                errors.push(
                    `${decisionsPath}: ${decision.id} references missing task ${taskId}`,
                );
            }
        }
    }

    validateDependencyCycles(roadmap, errors);

    return errors;
}

async function findDocumentReferences(taskId, docsDirectory) {
    const entries = (await readdir(docsDirectory, { withFileTypes: true }))
        .filter(
            (entry) =>
                entry.isFile()
                && entry.name.endsWith('.md')
                && !entry.name.endsWith('.orig'),
        )
        .sort((left, right) => left.name.localeCompare(right.name));
    const references = [];
    const taskReference = new RegExp(`\\b${taskId}\\b`, 'u');

    for (const entry of entries) {
        const filename = path.join(docsDirectory, entry.name);
        const lines = (await readFile(filename, 'utf8')).split(/\r?\n/u);
        const lineNumbers = [];

        lines.forEach((line, index) => {
            if (taskReference.test(line)) {
                lineNumbers.push(index + 1);
            }
        });

        if (lineNumbers.length > 0) {
            references.push({
                lines: lineNumbers,
                path: filename.replaceAll('\\', '/'),
            });
        }
    }

    return references;
}

function taskField(task, name) {
    return withoutPeriod(task.fields.get(name)?.value ?? '');
}

export async function resolveTaskContext(taskId, options = {}) {
    if (!taskPattern.test(taskId)) {
        throw new Error(`Task ID "${taskId}" must use AAA-NN format`);
    }

    const roadmapPath = options.roadmapPath ?? 'docs/ROADMAP.md';
    const decisionsPath = options.decisionsPath ?? 'docs/DECISIONS.md';
    const docsDirectory = options.docsDirectory ?? path.dirname(roadmapPath);
    const [roadmapContent, decisionsContent] = await Promise.all([
        readFile(roadmapPath, 'utf8'),
        readFile(decisionsPath, 'utf8'),
    ]);
    const roadmap = parseRoadmap(roadmapContent, roadmapPath);
    const decisions = parseDecisions(decisionsContent, decisionsPath);
    const errors = await validateContextSources({
        decisions,
        decisionsPath,
        roadmap,
        roadmapPath,
    });

    if (errors.length > 0) {
        throw new Error(errors.join('\n'));
    }

    const task = roadmap.get(taskId);

    if (!task) {
        throw new Error(`Unknown roadmap task ${taskId}`);
    }

    const dependencies = task.dependencies.map((id) => {
        if (taskPattern.test(id)) {
            const dependency = roadmap.get(id);

            return {
                id,
                kind: 'task',
                ready: dependency.status === 'Not recorded'
                    ? null
                    : dependency.status.startsWith('Complete'),
                status: dependency.status,
                title: dependency.title,
            };
        }

        const decision = decisions.get(id);

        return {
            id,
            kind: 'decision',
            ready: ['Decided', 'Superseded'].includes(decision.status),
            status: decision.status,
            title: decision.title,
        };
    });
    const relevantDecisions = [];

    for (const decision of decisions.values()) {
        const direct = task.dependencies.includes(decision.id);
        const relationship = relationshipFor(decision, taskId);

        if (!direct && !relationship) {
            continue;
        }

        const unresolved = !['Decided', 'Superseded'].includes(decision.status);

        relevantDecisions.push({
            blocking: unresolved && (direct || relationship === 'Blocked'),
            id: decision.id,
            line: decision.lineNumber,
            relationship: relationship ?? 'Direct dependency',
            status: decision.status,
            title: decision.title,
            unresolved,
        });
    }

    const implementation = task.fields.get('Implementation')?.value ?? '';
    const implementationDocuments = [
        ...implementation.matchAll(/\[([^\]]+)\]\(([^)]+\.md)\)/gu),
    ].map(([, label, link]) => ({
        label,
        path: path.join(docsDirectory, link).replaceAll('\\', '/'),
    }));

    return {
        blockers: relevantDecisions.filter(({ blocking }) => blocking),
        decisions: relevantDecisions,
        dependencies,
        documentReferences: await findDocumentReferences(taskId, docsDirectory),
        generatedFrom: {
            decisions: decisionsPath,
            roadmap: roadmapPath,
        },
        implementationDocuments,
        task: {
            acceptanceCriteria: taskField(task, 'Acceptance criteria'),
            dependencyExpression: task.dependencyText,
            estimatedSize: taskField(task, 'Estimated size'),
            id: task.id,
            line: task.lineNumber,
            outcome: taskField(task, 'Outcome'),
            priority: task.priority,
            risk: taskField(task, 'Risk'),
            section: task.section,
            status: task.status,
            suggestedAutomatedTests: taskField(task, 'Suggested automated tests'),
            title: task.title,
        },
    };
}

export function renderTaskContext(context) {
    const lines = [
        `# Task context: ${context.task.id}`,
        '',
        '> Generated from the authoritative roadmap and decision register. Do not edit or',
        '> store this output as a second source of truth.',
        '',
        '## Task',
        '',
        `- **Title:** ${context.task.title}`,
        `- **Section:** ${context.task.section}`,
        `- **Priority / risk / size:** ${context.task.priority} / ${context.task.risk} / ${context.task.estimatedSize}`,
        `- **Status:** ${context.task.status}`,
        `- **Source:** ${context.generatedFrom.roadmap}:${context.task.line}`,
        `- **Outcome:** ${context.task.outcome}`,
        `- **Acceptance criteria:** ${context.task.acceptanceCriteria}`,
        `- **Suggested automated tests:** ${context.task.suggestedAutomatedTests}`,
        '',
        '## Dependencies',
        '',
    ];

    if (context.task.dependencyExpression !== 'None') {
        lines.push(`- Declared expression: ${context.task.dependencyExpression}.`, '');
    }

    if (
        context.dependencies.length === 0
        && context.task.dependencyExpression !== 'None'
    ) {
        lines.push('- No discrete task or decision IDs; verify this dependency manually.');
    } else if (context.dependencies.length === 0) {
        lines.push('- None.');
    } else {
        for (const dependency of context.dependencies) {
            const readiness = dependency.ready === null
                ? 'readiness not recorded'
                : dependency.ready
                    ? 'ready'
                    : 'not ready';
            lines.push(
                `- ${dependency.id} -- ${dependency.title}; ${dependency.status}; ${readiness}.`,
            );
        }
    }

    lines.push('', '## Relevant decisions', '');

    if (context.decisions.length === 0) {
        lines.push('- None found.');
    } else {
        for (const decision of context.decisions) {
            lines.push(
                `- ${decision.id} -- ${decision.title}; ${decision.status}; ${decision.relationship}; ${context.generatedFrom.decisions}:${decision.line}.`,
            );
        }
    }

    lines.push('', '## Unresolved blockers', '');

    if (context.blockers.length === 0) {
        lines.push('- None detected.');
    } else {
        for (const blocker of context.blockers) {
            lines.push(`- ${blocker.id} -- ${blocker.title} (${blocker.status}).`);
        }
    }

    lines.push('', '## Implementation documents', '');

    if (context.implementationDocuments.length === 0) {
        lines.push('- None linked from the roadmap entry.');
    } else {
        for (const document of context.implementationDocuments) {
            lines.push(`- ${document.path} -- ${document.label}.`);
        }
    }

    lines.push('', '## Other document references', '');

    for (const reference of context.documentReferences) {
        lines.push(`- ${reference.path}: lines ${reference.lines.join(', ')}.`);
    }

    lines.push(
        '',
        '## Context boundary',
        '',
        '- Load only the relevant sections identified above, then inspect current code and',
        '  tests for implementation evidence.',
        '- Likely code areas and focused tests are deliberately discovered from current',
        '  repository state rather than stored as unvalidated task mappings.',
        '',
    );

    return lines.join('\n');
}

function parseArguments(arguments_) {
    const options = {
        decisionsPath: 'docs/DECISIONS.md',
        docsDirectory: 'docs',
        format: 'markdown',
        roadmapPath: 'docs/ROADMAP.md',
        validate: false,
    };
    let taskId = null;

    for (let index = 0; index < arguments_.length; index += 1) {
        const argument = arguments_[index];

        if (argument === '--validate') {
            options.validate = true;
        } else if (argument === '--json') {
            options.format = 'json';
        } else if (
            argument === '--roadmap'
            || argument === '--decisions'
            || argument === '--docs-dir'
        ) {
            const value = arguments_[index + 1];

            if (!value) {
                throw new Error(`Missing value for ${argument}`);
            }

            const optionName = {
                '--decisions': 'decisionsPath',
                '--docs-dir': 'docsDirectory',
                '--roadmap': 'roadmapPath',
            }[argument];
            options[optionName] = value;
            index += 1;
        } else if (!taskId) {
            taskId = argument;
        } else {
            throw new Error(`Unexpected argument "${argument}"`);
        }
    }

    return { options, taskId };
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : '';

if (invokedPath === fileURLToPath(import.meta.url)) {
    try {
        const { options, taskId } = parseArguments(process.argv.slice(2));

        if (options.validate) {
            const [roadmapContent, decisionsContent] = await Promise.all([
                readFile(options.roadmapPath, 'utf8'),
                readFile(options.decisionsPath, 'utf8'),
            ]);
            const roadmap = parseRoadmap(roadmapContent, options.roadmapPath);
            const decisions = parseDecisions(decisionsContent, options.decisionsPath);
            const errors = await validateContextSources({
                decisions,
                decisionsPath: options.decisionsPath,
                roadmap,
                roadmapPath: options.roadmapPath,
            });

            if (errors.length > 0) {
                throw new Error(errors.join('\n'));
            }

            console.log(
                `Task context sources are valid (${roadmap.size} tasks, ${decisions.size} decisions).`,
            );
        } else {
            if (!taskId) {
                throw new Error('Usage: npm run context:task -- TASK-ID [--json]');
            }

            const context = await resolveTaskContext(taskId, options);
            console.log(
                options.format === 'json'
                    ? JSON.stringify(context, null, 2)
                    : renderTaskContext(context),
            );
        }
    } catch (error) {
        console.error(`Task context could not be generated: ${error.message}`);
        process.exitCode = 1;
    }
}
