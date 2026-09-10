import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    parseDecisions,
    parseRoadmap,
    relationshipFor,
    validateContextSources,
} from './task-context.mjs';

const taskPattern = /^[A-Z]{2,4}-\d{2}$/u;
const decisionPattern = /^DEC-\d{3}$/u;
const referencePattern = /\b(?:[A-Z]{2,4}-\d{2}|DEC-\d{3})\b/gu;
const referenceLikePattern = /\b[A-Z]{2,4}-\d+\b/gu;

function unique(values) {
    return [...new Set(values)];
}

function code(value) {
    const marker = String.fromCharCode(96);

    return marker + value + marker;
}

function markdownCell(value) {
    return value
        .replaceAll('|', '\\|')
        .replaceAll(/\s+/gu, ' ')
        .trim();
}

function idList(values) {
    return values.length === 0 ? '-' : values.map(code).join(', ');
}

function referenceExpression(value) {
    if (value === 'None') {
        return '-';
    }

    return markdownCell(value).replace(referencePattern, (reference) => code(reference));
}

export function parseDomainSections(
    content,
    filename = 'docs/DOMAIN_MODEL.md',
) {
    const lines = content.split(/\r?\n/u);
    const headings = [];

    lines.forEach((line, index) => {
        const match = line.match(/^(##|###) (.+)$/u);

        if (match) {
            headings.push({
                depth: match[1].length,
                index,
                line: index + 1,
                title: match[2],
            });
        }
    });

    const sections = headings.map((heading, index) => {
        const endIndex = headings[index + 1]?.index ?? lines.length;
        const text = lines.slice(heading.index, endIndex).join('\n');
        const references = unique(text.match(referencePattern) ?? []);

        return {
            ...heading,
            decisions: references.filter((reference) =>
                decisionPattern.test(reference),
            ),
            endLine: endIndex,
            tasks: references.filter((reference) => taskPattern.test(reference)),
        };
    });
    const malformedReferences = unique(
        (content.match(referenceLikePattern) ?? []).filter(
            (reference) =>
                !taskPattern.test(reference)
                && !decisionPattern.test(reference),
        ),
    );

    return { filename, malformedReferences, sections };
}

function collectRelationships(roadmap, decisions) {
    const decisionsByTask = new Map(
        [...roadmap.keys()].map((taskId) => [taskId, []]),
    );
    const tasksByDecision = new Map(
        [...decisions.keys()].map((decisionId) => [decisionId, []]),
    );

    for (const decision of decisions.values()) {
        for (const task of roadmap.values()) {
            const relationship = relationshipFor(decision, task.id);
            const directDependency = task.dependencies.includes(decision.id);

            if (!relationship && !directDependency) {
                continue;
            }

            const label = relationship ?? 'Direct dependency';
            decisionsByTask.get(task.id).push({
                id: decision.id,
                relationship: label,
            });
            tasksByDecision.get(decision.id).push({
                id: task.id,
                relationship: label,
            });
        }
    }

    return { decisionsByTask, tasksByDecision };
}

function collectReverseDependencies(roadmap) {
    const reverseDependencies = new Map(
        [...roadmap.keys()].map((taskId) => [taskId, []]),
    );

    for (const task of roadmap.values()) {
        for (const dependency of task.dependencies) {
            if (taskPattern.test(dependency) && roadmap.has(dependency)) {
                reverseDependencies.get(dependency).push(task.id);
            }
        }
    }

    return reverseDependencies;
}

function collectDomainRelationships(domain, roadmap, decisions) {
    const sectionsByTask = new Map(
        [...roadmap.keys()].map((taskId) => [taskId, []]),
    );
    const sectionsByDecision = new Map(
        [...decisions.keys()].map((decisionId) => [decisionId, []]),
    );

    for (const section of domain.sections) {
        for (const taskId of section.tasks) {
            if (roadmap.has(taskId)) {
                sectionsByTask.get(taskId).push(section);
            }
        }

        for (const decisionId of section.decisions) {
            if (decisions.has(decisionId)) {
                sectionsByDecision.get(decisionId).push(section);
            }
        }
    }

    return { sectionsByDecision, sectionsByTask };
}

function validateDomainReferences(domain, roadmap, decisions) {
    const knownPrefixes = new Set([
        'DEC',
        ...[...roadmap.keys()].map((taskId) => taskId.split('-')[0]),
    ]);
    const errors = domain.malformedReferences
        .filter((reference) => knownPrefixes.has(reference.split('-')[0]))
        .map(
            (reference) =>
                `${domain.filename}: malformed roadmap or decision reference "${reference}"`,
        );

    for (const section of domain.sections) {
        for (const taskId of section.tasks) {
            if (!roadmap.has(taskId)) {
                errors.push(
                    `${domain.filename}:${section.line}: section "${section.title}" references missing roadmap task ${taskId}`,
                );
            }
        }

        for (const decisionId of section.decisions) {
            if (!decisions.has(decisionId)) {
                errors.push(
                    `${domain.filename}:${section.line}: section "${section.title}" references missing decision ${decisionId}`,
                );
            }
        }
    }

    return errors;
}

function validateDecisionRelationships(decisions, roadmap, decisionsPath) {
    const errors = [];

    for (const decision of decisions.values()) {
        const relationships = decision.fields.get('Backlog relationships')?.value ?? '';
        const taskIds = unique(
            (relationships.match(referencePattern) ?? []).filter((reference) =>
                taskPattern.test(reference),
            ),
        );

        for (const taskId of taskIds) {
            if (roadmap.has(taskId) && !relationshipFor(decision, taskId)) {
                errors.push(
                    `${decisionsPath}: ${decision.id} relationship to ${taskId} cannot be classified`,
                );
            }
        }
    }

    return errors;
}

function relationshipList(relationships) {
    return relationships.length === 0
        ? '-'
        : relationships
            .map(({ id, relationship }) => `${code(id)} (${relationship})`)
            .join(', ');
}

function domainSectionList(sections) {
    return sections.length === 0
        ? '-'
        : sections
            .map((section) => `${markdownCell(section.title)} (line ${section.line})`)
            .join(', ');
}

export function renderContextIndex({
    decisions,
    domain,
    roadmap,
}) {
    const { decisionsByTask, tasksByDecision } = collectRelationships(
        roadmap,
        decisions,
    );
    const reverseDependencies = collectReverseDependencies(roadmap);
    const { sectionsByDecision, sectionsByTask } = collectDomainRelationships(
        domain,
        roadmap,
        decisions,
    );
    const lines = [
        '# Generated context index',
        '',
        `> Generated from ${code('docs/ROADMAP.md')}, ${code('docs/DECISIONS.md')}, and`,
        `> ${code('docs/DOMAIN_MODEL.md')}. Do not edit this file manually. Run`,
        `> ${code('./vendor/bin/sail npm run context:index:update')} after changing a source.`,
        '',
        'This is lookup-only routing evidence, not a source of product truth. Search for',
        'the relevant stable ID or domain heading; do not load this whole file by default.',
        'Only explicit, machine-verifiable references are included.',
        '',
        '## Roadmap task index',
        '',
        '| Task | Roadmap domain | Status | Dependency expression | Referenced by | Decisions | Domain-model sections |',
        '| --- | --- | --- | --- | --- | --- | --- |',
    ];

    for (const task of roadmap.values()) {
        const cells = [
            `${code(task.id)} - ${markdownCell(task.title)}`,
            markdownCell(task.section),
            markdownCell(task.status),
            referenceExpression(task.dependencyText),
            idList(reverseDependencies.get(task.id)),
            relationshipList(decisionsByTask.get(task.id)),
            domainSectionList(sectionsByTask.get(task.id)),
        ];
        lines.push(`| ${cells.join(' | ')} |`);
    }

    lines.push(
        '',
        '## Decision impact index',
        '',
        '| Decision | Status | Roadmap relationships | Domain-model sections |',
        '| --- | --- | --- | --- |',
    );

    for (const decision of decisions.values()) {
        const cells = [
            `${code(decision.id)} - ${markdownCell(decision.title)}`,
            markdownCell(decision.status),
            relationshipList(tasksByDecision.get(decision.id)),
            domainSectionList(sectionsByDecision.get(decision.id)),
        ];
        lines.push(`| ${cells.join(' | ')} |`);
    }

    lines.push(
        '',
        '## Domain-model section index',
        '',
        '| Section | Lines | Roadmap tasks | Decisions |',
        '| --- | --- | --- | --- |',
    );

    for (const section of domain.sections) {
        const cells = [
            `${section.depth === 3 ? 'Concept: ' : ''}${markdownCell(section.title)}`,
            section.line === section.endLine
                ? String(section.line)
                : `${section.line}-${section.endLine}`,
            idList(section.tasks),
            idList(section.decisions),
        ];
        lines.push(`| ${cells.join(' | ')} |`);
    }

    lines.push('');

    return lines.join('\n');
}

export async function buildContextIndex(options = {}) {
    const roadmapPath = options.roadmapPath ?? 'docs/ROADMAP.md';
    const decisionsPath = options.decisionsPath ?? 'docs/DECISIONS.md';
    const domainPath = options.domainPath ?? 'docs/DOMAIN_MODEL.md';
    const [roadmapContent, decisionsContent, domainContent] = await Promise.all([
        readFile(roadmapPath, 'utf8'),
        readFile(decisionsPath, 'utf8'),
        readFile(domainPath, 'utf8'),
    ]);
    const roadmap = parseRoadmap(roadmapContent, roadmapPath);
    const decisions = parseDecisions(decisionsContent, decisionsPath);
    const domain = parseDomainSections(domainContent, domainPath);
    const errors = [
        ...await validateContextSources({
            decisions,
            decisionsPath,
            roadmap,
            roadmapPath,
        }),
        ...validateDomainReferences(domain, roadmap, decisions),
        ...validateDecisionRelationships(decisions, roadmap, decisionsPath),
    ];

    if (errors.length > 0) {
        throw new Error(errors.join('\n'));
    }

    return renderContextIndex({ decisions, domain, roadmap });
}

export async function checkContextIndex(options = {}) {
    const outputPath = options.outputPath ?? 'docs/CONTEXT_INDEX.md';
    const expected = await buildContextIndex(options);
    let actual;

    try {
        actual = await readFile(outputPath, 'utf8');
    } catch {
        throw new Error(
            `${outputPath} is missing; run "npm run context:index:update"`,
        );
    }

    if (actual !== expected) {
        throw new Error(
            `${outputPath} is stale; run "npm run context:index:update"`,
        );
    }

    return expected;
}

function parseArguments(arguments_) {
    const options = {};
    let mode = 'print';
    const optionNames = new Map([
        ['--roadmap', 'roadmapPath'],
        ['--decisions', 'decisionsPath'],
        ['--domain', 'domainPath'],
        ['--output', 'outputPath'],
    ]);

    for (let index = 0; index < arguments_.length; index += 1) {
        const argument = arguments_[index];

        if (argument === '--check' || argument === '--write') {
            if (mode !== 'print') {
                throw new Error('Use only one of --check or --write');
            }

            mode = argument.slice(2);
            continue;
        }

        const optionName = optionNames.get(argument);
        const value = arguments_[index + 1];

        if (!optionName || !value) {
            throw new Error(`Unknown or incomplete option "${argument}"`);
        }

        options[optionName] = value;
        index += 1;
    }

    return { mode, options };
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : '';

if (invokedPath === fileURLToPath(import.meta.url)) {
    try {
        const { mode, options } = parseArguments(process.argv.slice(2));
        const outputPath = options.outputPath ?? 'docs/CONTEXT_INDEX.md';

        if (mode === 'check') {
            await checkContextIndex(options);
            console.log(`${outputPath} is current.`);
        } else {
            const content = await buildContextIndex(options);

            if (mode === 'write') {
                await writeFile(outputPath, content);
                console.log(`Updated ${outputPath}.`);
            } else {
                process.stdout.write(content);
            }
        }
    } catch (error) {
        console.error(`Context index failed: ${error.message}`);
        process.exitCode = 1;
    }
}
