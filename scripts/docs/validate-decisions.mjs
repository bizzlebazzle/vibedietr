import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const allowedStatuses = new Set([
    'Open',
    'Research required',
    'Owner input required',
    'Decided',
    'Superseded',
]);

const requiredFields = [
    'Question requiring resolution',
    'Why it matters',
    'Status',
    'Owner',
    'Alternatives',
    'Backlog relationships',
    'Resolution condition',
    'Final decision and rationale',
];

function stripSentencePeriod(value) {
    return value.endsWith('.') ? value.slice(0, -1) : value;
}

function parseArguments(arguments_) {
    const defaults = {
        decisions: 'docs/DECISIONS.md',
        productSpec: 'docs/PRODUCT_SPEC.md',
        roadmap: 'docs/ROADMAP.md',
        identities: 'scripts/docs/decision-identities.json',
    };
    const optionNames = new Map([
        ['--decisions', 'decisions'],
        ['--product-spec', 'productSpec'],
        ['--roadmap', 'roadmap'],
        ['--identities', 'identities'],
    ]);

    for (let index = 0; index < arguments_.length; index += 2) {
        const option = arguments_[index];
        const value = arguments_[index + 1];
        const name = optionNames.get(option);

        if (!name || !value) {
            throw new Error(`Unknown or incomplete option "${option ?? ''}"`);
        }

        defaults[name] = value;
    }

    return defaults;
}

function parseDecisionEntries(filename, content, errors) {
    const lines = content.split(/\r?\n/);
    const headings = [];

    lines.forEach((line, index) => {
        if (line.startsWith('## ')) {
            headings.push({ line, lineNumber: index + 1, index });
        }
    });

    const summaryHeading = headings.find(({ line }) => line === '## Register summary');
    const checklistHeading = headings.find(({ line }) => line === '## Manual validation checklist');

    if (!summaryHeading) {
        errors.push(`${filename}: missing required heading "Register summary"`);
    }

    if (!checklistHeading) {
        errors.push(`${filename}: missing required heading "Manual validation checklist"`);
    }

    if (!summaryHeading || !checklistHeading) {
        return { entries: [], lines };
    }

    const decisionHeadings = headings.filter(
        ({ index }) => index > summaryHeading.index && index < checklistHeading.index,
    );
    const entries = [];

    decisionHeadings.forEach((heading, headingIndex) => {
        const match = heading.line.match(/^## (DEC-\d{3}) \u2014 (.+)$/);

        if (!match) {
            errors.push(
                `${filename}:${heading.lineNumber}: decision heading must use "## DEC-NNN -- Title"`,
            );
            return;
        }

        const endIndex = decisionHeadings[headingIndex + 1]?.index ?? checklistHeading.index;
        const fields = new Map();
        let activeField = null;

        for (let index = heading.index + 1; index < endIndex; index += 1) {
            const fieldMatch = lines[index].match(/^- \*\*([^*]+):\*\*\s*(.*)$/);

            if (fieldMatch) {
                const [, label, value] = fieldMatch;

                if (fields.has(label)) {
                    errors.push(`${filename}: ${match[1]} has duplicate field "${label}"`);
                }

                fields.set(label, { value, lineNumber: index + 1 });
                activeField = label;
            } else if (activeField && /^\s{2}\S/.test(lines[index])) {
                const field = fields.get(activeField);
                field.value = `${field.value} ${lines[index].trim()}`.trim();
            }
        }

        entries.push({
            id: match[1],
            title: match[2],
            lineNumber: heading.lineNumber,
            fields,
        });
    });

    return { entries, lines };
}

function validateEntryFields(filename, entries, errors) {
    for (const entry of entries) {
        for (const field of requiredFields) {
            const value = entry.fields.get(field)?.value.trim();

            if (!value) {
                errors.push(`${filename}: ${entry.id} is missing required field "${field}"`);
            }
        }

        const constraintFields = [...entry.fields.keys()].filter((field) =>
            field.startsWith('Existing constraints from '),
        );

        if (constraintFields.length === 0) {
            errors.push(
                `${filename}: ${entry.id} is missing required field "Existing constraints from source"`,
            );
        } else if (constraintFields.length > 1) {
            errors.push(
                `${filename}: ${entry.id} has multiple "Existing constraints from source" fields`,
            );
        } else {
            const label = constraintFields[0];
            const value = entry.fields.get(label)?.value.trim();

            if (!/^Existing constraints from (?:`PRODUCT_SPEC\.md`|DEC-\d{3})$/.test(label)) {
                errors.push(`${filename}: ${entry.id} has invalid required field name "${label}"`);
            }

            if (!value) {
                errors.push(`${filename}: ${entry.id} has an empty required field "${label}"`);
            }
        }

        const status = stripSentencePeriod(entry.fields.get('Status')?.value.trim() ?? '');

        if (status && !allowedStatuses.has(status)) {
            errors.push(
                `${filename}: ${entry.id} has unknown status "${status}"; allowed statuses are ${[
                    ...allowedStatuses,
                ].join(', ')}`,
            );
        }
    }
}

function validateUniqueDecisionIds(filename, entries, errors) {
    const seen = new Map();

    for (const entry of entries) {
        if (seen.has(entry.id)) {
            errors.push(
                `${filename}:${entry.lineNumber}: duplicate decision ID ${entry.id}; first used on line ${seen.get(entry.id)}`,
            );
        } else {
            seen.set(entry.id, entry.lineNumber);
        }
    }
}

function parseSummary(lines) {
    const rows = [];

    lines.forEach((line, index) => {
        const match = line.match(/^\| (DEC-\d{3}) \| ([^|]+) \| ([^|]+) \| ([^|]+) \|$/);

        if (match) {
            rows.push({
                id: match[1],
                title: match[2].trim(),
                status: match[3].trim(),
                owner: match[4].trim(),
                lineNumber: index + 1,
            });
        }
    });

    return rows;
}

function validateSummary(filename, entries, lines, errors) {
    const rows = parseSummary(lines);
    const entriesById = new Map(entries.map((entry) => [entry.id, entry]));
    const seenRows = new Set();

    for (const row of rows) {
        if (seenRows.has(row.id)) {
            errors.push(`${filename}:${row.lineNumber}: duplicate register-summary ID ${row.id}`);
            continue;
        }

        seenRows.add(row.id);
        const entry = entriesById.get(row.id);

        if (!entry) {
            errors.push(`${filename}:${row.lineNumber}: summary references missing decision ${row.id}`);
            continue;
        }

        const status = stripSentencePeriod(entry.fields.get('Status')?.value.trim() ?? '');
        const owner = stripSentencePeriod(entry.fields.get('Owner')?.value.trim() ?? '');

        if (row.title !== entry.title) {
            errors.push(`${filename}: ${row.id} title differs between the summary and entry heading`);
        }

        if (row.status !== status) {
            errors.push(`${filename}: ${row.id} status differs between the summary and entry`);
        }

        if (row.owner !== owner) {
            errors.push(`${filename}: ${row.id} owner differs between the summary and entry`);
        }
    }

    for (const entry of entries) {
        if (!seenRows.has(entry.id)) {
            errors.push(`${filename}: ${entry.id} is missing from the register summary`);
        }
    }
}

function validateDecisionIdentities(filename, entries, identities, errors) {
    const entriesById = new Map(entries.map((entry) => [entry.id, entry]));

    for (const entry of entries) {
        if (!(entry.id in identities)) {
            errors.push(`${filename}: ${entry.id} is missing from the stable decision identity ledger`);
        } else if (identities[entry.id] !== entry.title) {
            errors.push(
                `${filename}: ${entry.id} is registered as "${identities[entry.id]}" and must not be reused as "${entry.title}"`,
            );
        }
    }

    for (const id of Object.keys(identities)) {
        if (!/^DEC-\d{3}$/.test(id)) {
            errors.push(`${filename}: identity ledger key "${id}" must use DEC-NNN format`);
        } else if (!entriesById.has(id)) {
            errors.push(`${filename}: identity ledger references missing decision ${id}`);
        }
    }
}

function parseRoadmapIds(filename, content, errors) {
    const ids = new Set();

    content.split(/\r?\n/).forEach((line, index) => {
        const match = line.match(/^### ([A-Z]{3}-\d{2}) \u2014/);

        if (!match) {
            return;
        }

        if (ids.has(match[1])) {
            errors.push(`${filename}:${index + 1}: duplicate backlog ID ${match[1]}`);
        }

        ids.add(match[1]);
    });

    return ids;
}

function validateBacklogReferences(filename, entries, roadmapIds, errors) {
    for (const entry of entries) {
        const relationships = entry.fields.get('Backlog relationships')?.value ?? '';
        const references = relationships.match(/\b[A-Z]{3}-\d+\b/g) ?? [];

        for (const reference of new Set(references)) {
            if (reference.startsWith('DEC-')) {
                continue;
            }

            if (!/^[A-Z]{3}-\d{2}$/.test(reference)) {
                errors.push(
                    `${filename}: ${entry.id} has invalid backlog reference "${reference}"; expected AAA-NN`,
                );
            } else if (!roadmapIds.has(reference)) {
                errors.push(
                    `${filename}: ${entry.id} references backlog item ${reference}, which is missing from docs/ROADMAP.md`,
                );
            }
        }
    }
}

function validateDeferredCoverage(filename, content, decisionIds, errors) {
    const lines = content.split(/\r?\n/);
    const start = lines.indexOf('## Deferred design and policy decisions');

    if (start === -1) {
        errors.push(`${filename}: missing required heading "Deferred design and policy decisions"`);
        return;
    }

    let end = lines.findIndex((line, index) => index > start && line.startsWith('## '));
    end = end === -1 ? lines.length : end;
    const bullets = [];

    for (let index = start + 1; index < end; index += 1) {
        if (lines[index].startsWith('- ')) {
            bullets.push({ lineNumber: index + 1, text: lines[index] });
        } else if (bullets.length > 0 && /^\s{2}\S/.test(lines[index])) {
            bullets.at(-1).text += ` ${lines[index].trim()}`;
        }
    }

    if (bullets.length === 0) {
        errors.push(`${filename}: deferred-decision section contains no decision bullets`);
        return;
    }

    const referenced = new Map();

    for (const bullet of bullets) {
        const marker = bullet.text.match(/\bDecision: (DEC-\d{3}(?:, DEC-\d{3})*)\./);

        if (!marker) {
            errors.push(
                `${filename}:${bullet.lineNumber}: deferred decision is missing a "Decision: DEC-NNN." marker`,
            );
            continue;
        }

        for (const id of marker[1].split(', ')) {
            if (!decisionIds.has(id)) {
                errors.push(
                    `${filename}:${bullet.lineNumber}: deferred decision references missing decision ${id}`,
                );
            } else if (referenced.has(id)) {
                errors.push(
                    `${filename}:${bullet.lineNumber}: deferred decision ${id} is already referenced on line ${referenced.get(id)}`,
                );
            } else {
                referenced.set(id, bullet.lineNumber);
            }
        }
    }
}

export async function validateDocumentation(paths) {
    const errors = [];
    const [decisions, productSpec, roadmap, identitiesJson] = await Promise.all([
        readFile(paths.decisions, 'utf8'),
        readFile(paths.productSpec, 'utf8'),
        readFile(paths.roadmap, 'utf8'),
        readFile(paths.identities, 'utf8'),
    ]);
    const identities = JSON.parse(identitiesJson);
    const { entries, lines } = parseDecisionEntries(paths.decisions, decisions, errors);

    validateUniqueDecisionIds(paths.decisions, entries, errors);
    validateEntryFields(paths.decisions, entries, errors);
    validateSummary(paths.decisions, entries, lines, errors);
    validateDecisionIdentities(paths.decisions, entries, identities, errors);

    const roadmapIds = parseRoadmapIds(paths.roadmap, roadmap, errors);
    validateBacklogReferences(paths.decisions, entries, roadmapIds, errors);
    validateDeferredCoverage(
        paths.productSpec,
        productSpec,
        new Set(entries.map(({ id }) => id)),
        errors,
    );

    return errors;
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : '';

if (invokedPath === fileURLToPath(import.meta.url)) {
    try {
        const paths = parseArguments(process.argv.slice(2));
        const errors = await validateDocumentation(paths);

        if (errors.length > 0) {
            errors.forEach((error) => console.error(error));
            process.exitCode = 1;
        } else {
            console.log('Decision-register documentation is valid.');
        }
    } catch (error) {
        console.error(`Documentation validation could not run: ${error.message}`);
        process.exitCode = 1;
    }
}
