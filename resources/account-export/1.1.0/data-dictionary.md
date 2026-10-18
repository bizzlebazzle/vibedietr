# VibeDietr account export 1.1.0

The bundled Draft 2020-12 JSON Schema fixes each data envelope and record field.
Validate a file against `$defs/<domain>_envelope` in the bundled schema. All
objects reject additional fields; optional fields describe older stored shapes.
The `x-` annotations document producer projection, not additional consumer rules.

| File | Counting unit | Contents |
| --- | --- | --- |
| account | account | Own portable identity and optional public profile |
| recipes | recipes | Own current working content, every retained version, active revision pointer, original and effective nutrition, override and completed recalculation history |
| organisation | collections | Collections, private tags, their own memberships, bookmarks and minimized remix lineage |
| plans | plans | Own plans, days, slots, pinned recipe/catalogue and private one-off snapshots, outgoing share aliases |
| diary | transitions | Ad-hoc sources, states, complete ordered transitions and retained nutrition snapshots |
| targets | profiles | Own profiles and targets, phases and effective-date values including deleted-profile snapshots |
| imports | imports | Own retained source text, warnings and processor provenance |
| proposals | corrections | Own correction changes, legacy ingredients, initial catalogue submission evidence and term suggestions |

Counts refer to the named collection only, not its nested records. Byte lengths
are uncompressed UTF-8 file lengths including the final LF. Envelopes use
`schema_version` and `data`. Manifest metadata describes the exact edition and
actual files. Its generation instant is after the committed content read began;
it does not promise a backup at the request timestamp.

IDs are strings with a record-type prefix and stored identifier; they join
across files. Account identity is `self`. Section keys are recipe-snapshot local.
State, predecessor, reversal target and nutrition-snapshot references preserve
the original history graph, including time-only snapshot reuse. Source IDs
refer to recipe/catalogue content, never foreign user IDs. Unavailable bookmarks
and lineage are tombstones without source title, author, profile or account.
Recipient aliases identify only equality within this archive, with no cross-
archive identity guarantee. Incoming shares never expand foreign plan graphs.

SQL decimal fields and recorded JSON decimal strings retain their exact stored
digits. No exponent, thousands grouping or display rounding is applied. Null,
empty text, empty collections, known zero and source statuses such as trace or
less-than limits remain distinct. No missing nutrient is invented. Bases and
units remain recorded: per recipe/serving/100 gram/100 millilitre are different.
Legacy raw provider blobs are omitted; recorded legacy aliases such as fiber and proteins remain lexical facts. The ten nutrient identifiers are energy_kcal (kcal), energy_kj (kJ), fat (g),
saturated_fat (g), carbohydrates (g), sugars (g), fibre (g), protein (g), salt
(g) and sodium (mg); actual stored units are authoritative. Estimates retain
their source, policy versions, inputs, exclusions and conversion provenance.
Imported source, creator override, one-off entry and ingredient estimates remain
separate. Original version estimates are not replaced by later recalculations.

UTC instants use RFC 3339 Z, retaining stored fractional precision. Calendar
dates remain dates. Consumption also retains local wall time, IANA timezone,
offset minutes and effective diary date. ZIP metadata is not a history clock.

Privacy omissions are categories, not invented null domain facts: no credentials,
raw audit/security stores, other-user identity, restricted moderation evidence, raw provider payloads,
transient originals/canonical files or foreign live content. Imported provenance
omits URLs (which may contain secrets), internal import IDs and correlation IDs;
owned source text is preserved without text rewriting. Stored snapshot display text remains historical wording, separate from exact machine facts; missing source limits are never reconstructed from rounded display text. Legacy ingredient/submission
evidence is explicitly in `proposals.legacy_ingredients` and
`proposals.catalogue_submissions`, not a shared catalogue dump.

Versioning uses major for incompatible packaging/type/meaning/privacy changes,
minor for approved optional additions and patch for documentation corrections
that change neither valid data nor meaning. Preserve released schemas. Schema
changes never authorize new disclosure or rewrite immutable historical content.

## Archived private consumption history (1.1.0)

The diary includes owner-only archived_history for permanently removed retained plans. It preserves original entry facts, immutable consumption chains, nutrition and target snapshots, dates and slots. Source plan references identify historical context; they do not make the removed plan available or restorable.
