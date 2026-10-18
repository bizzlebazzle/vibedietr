# Account export format investigation

## Status and scope

Research date: 2026-10-18. Decision: DEC-008. **Owner input required**;
**Product owner**. Everything labelled proposed below awaits owner approval.
This document supplies choices, not DEP-07 implementation or an export service.
No real account data was used. Examples are synthetic export projections of
current domain records, not literal database dumps or completed JSON Schemas.

`context:task -- DEC-008` rejects decision IDs (the resolver accepts roadmap
IDs). `./vendor/bin/sail npm run context:task -- DEP-07` successfully routed the
decision, dependencies, acceptance criteria and privacy references.

Relationships remain: **Blocked:** DEP-07; **Constrained:** FND-09, DEP-04;
**Related:** DEP-09. DEP-07 also depends on DEP-05. Approval here does not
complete DEP-07, add import/restore support, or certify legal portability.

## Confirmed constraints, without a new owner choice

- [Product specification](PRODUCT_SPEC.md#data-export) requires requester-owned
  account, recipes, plans, diary history and targets; viewable shares never
  authorize exporting another user's private account data.
- [DEP-07](ROADMAP.md#dep-07--p2--add-self-service-account-data-export) also
  names owned recipe versions, organisation, one-off items and owned proposals.
  Shared references are minimized. Authenticated, expiring, audited download
  and scheduled cleanup are future implementation requirements.
- [Privacy matrix](AUTHORIZATION_PRIVACY_MATRIX.md) separates ownership from
  visibility, and plan snapshots from live recipe access. Bookmarks are private
  pointers, not owned copies of their source content. Catalogue submitter
  identity is provenance, not ownership of the shared catalogue.
- [DEC-003/004](DECISIONS.md) and
  [measurement definitions](NUTRIENT_MEASUREMENT_DEFINITIONS.md) require full
  stored precision in machine data, explicit bases/units and provenance,
  preservation of original wording, and distinction between zero, unknown,
  trace and source limits. Calculated nutrition remains labelled an estimate.
  Human-readable nutrient values follow the existing display table.
- Historical snapshots are not rebuilt from today's recipes, catalogue or
  target profiles. Current effective recipe nutrition and immutable original
  version nutrition are distinct, as are override/recalculation histories.
- Uploads/photos and fetched webpages are transient, not recipe attachments.
  Keep retained owned extracted/pasted source text and permitted provenance;
  do not retain, recover or bundle original/canonical input files for export.
- The approved [retention schedule](AUDIT_RETENTION_SCHEDULE.md) already fixes
  archive/credential lifetime at seven calendar days from creation, with
  earlier final-purge handling and scoped holds as documented. Its security
  events have a separate six-month period. DEC-008 must not reset either clock
  or invent archive-backup/hold behaviour.
- Raw security/audit stores are purpose-restricted; account ownership is not
  authority to dump events, actor mappings, operational payloads, other-user
  information or moderator notes. No password hashes, remember/reset tokens,
  session credentials, TOTP seeds, recovery codes, signed URLs or storage keys
  belong in a portable content projection.

## Repository evidence and completeness map

The implementation is broader than some older introductory paragraphs in the
domain/privacy documents. The following current code/tests establish shape;
they do not override the authoritative product boundaries above.

| Export area | Current records and representative complexity | Evidence |
| --- | --- | --- |
| Account | Own name/email, nullable verification time, timezone, timestamps; optional public profile and independent listing booleans; browser-local theme is not persisted account data | `app/Models/User.php`, `PublicProfile.php`; DOMAIN_MODEL account/profile sections |
| Recipes | Draft content, current version pointer, immutable numbered versions, active revision; ordered ingredient text/quantity/standard or custom units; nullable sections/notes and snapshot-local section keys | `app/Domain/Recipes/RecipeVersionContent.php`, `app/Models/RecipeVersion.php`, `RecipeDraftRevision.php` |
| Recipe nutrition | Original estimate/imported values, version-specific creator overrides and append-only changes; completed recalculations and effective source selection; pinned match versions, confidence/review state and policy provenance | `app/Models/RecipeNutritionOverrideEvent.php`, `RecipeNutritionRecalculation.php`; DOMAIN_MODEL nutrition-source section |
| Organisation/lineage | Owned collections, private tags and memberships; recipe/plan bookmarks including unavailable tombstones; own remix content plus minimized source/version lineage | `app/Domain/Recipes/RecipeRemixAttributionPresenter.php`; `tests/Feature/Recipes/RecipeRemixTest.php`, `RecipeBookmarkTest.php` |
| Plans | Owned dated/reusable plans, day indexes and nullable dates, ordered standard/custom slots, pinned recipe/version and catalogue/version snapshots; private one-offs with entered and normalized nutrition | `app/Models/MealPlanRecipeEntry.php`, `MealPlanItemEntry.php`; `app/Domain/MealPlans/MealPlanRecipeEntryWriter.php` |
| Copies and shares | Copy owns independent content, preserves pinned snapshots and excludes source consumption, shares, bookmarks and review notifications; selected shares can carry acknowledged private recipe snapshots | `app/Domain/MealPlans/MealPlanCopier.php`; `tests/Feature/MealPlans/MealPlanCopyTest.php` |
| Diary | Ad-hoc source snapshots or plan-entry state; ordered consume/correct/reverse/reconsume transitions; predecessor and reversal target links; time-only correction reuses snapshot, quantity correction replaces it | `app/Models/DiaryEntry.php`, `DiaryConsumptionState.php`, `DiaryConsumptionTransition.php`; `tests/Feature/MealPlans/MealPlanConsumptionTest.php` |
| Consumption values | Immutable nutrition source, estimate status, actual amount/unit, original item/version references; independent nutrition snapshot per amount, never reconstructed | `app/Domain/Diary/ConsumptionNutritionSnapshotter.php`; `tests/Feature/MealPlans/ConsumptionNutritionSnapshotTest.php` |
| Targets | Named/default profiles with exact/minimum/maximum/range targets; dated phases with optional end/profile link and profile-name snapshot; effective-date value history survives profile deletion | `app/Models/NutritionTarget.php`, `MealPlanTargetPhase.php`, `MealPlanTargetPhaseValue.php`; `tests/Feature/MealPlans/MealPlanTargetPhaseTest.php` |
| Imports/proposals/legacy | Owned retained import text/provenance and review warnings; own submitted correction fields/reason/state, not moderator identity/evidence; legacy ingredient values and owned submission evidence, not the whole catalogue | `app/Models/RecipeImport.php`, `CatalogueCorrectionProposal.php`, `Ingredient.php`; `CATALOGUE_CORRECTIONS.md`, DOMAIN_MODEL legacy mapping section |

Tests were inspected for evidence, not run as export tests. No production export
exists to exercise. At DEP-07, use an explicit allowlist and cross-user fixtures
for every area rather than model `toArray()` or recursive eager serialization.

## Ownership and provenance treatment

| Situation | Safe reference | Data that must travel with owned history | Prohibited expansion / risk |
| --- | --- | --- | --- |
| Own recipe/version or revision | Typed own ID and version ID/number | Original owned text, structured fields, stored nutrition/provenance and histories | Do not substitute latest content for older versions |
| Own plan using somebody else's recipe | Recorded recipe/version identifier as provenance, not permission | Entry-owned pinned recipe and effective nutrition snapshots | Never traverse into source owner, current private recipe, source imports, unpublished revisions or unrelated versions |
| Own independent plan copy | New own plan/entry identities | Content already materialized in the copy, including legitimately copied private snapshot/one-off wording | Do not export source plan diary, targets, participants or source owner's account; copy service does not retain a source-plan relationship to invent |
| Share received, no copy | No new content ownership; omit foreign plan graph | None merely because it is viewable | Exporting its diary/targets or materializing foreign private content solely from view permission would leak data |
| Own recipe/plan bookmark | Stored durable source pointer, generic unavailable state when appropriate | Own bookmark and private organisation | Never fetch private/unavailable title, author, email, hidden profile or foreign bookmark owners |
| Own remix with unavailable source | Opaque recorded recipe/version provenance; unavailable tombstone and version number | Own independent recipe content | DEC-018 forbids decorating unavailable lineage with source title/label/link; never serialize `source_creator_user_id` or source user relationship |
| Catalogue match/contribution | Item/version identity, permitted factual source provenance | Factual/nutrition values already pinned to own version/entry; own submitted proposal text/changes | Submitter is not catalogue owner; exclude other submissions, raw provider responses, moderators, decisions' private notes and backfill submitter identities |
| Deleted source or target profile | Nullable live link plus stored historical reference when present | Existing snapshots, profile-name snapshot and effective-date target values | Never restore source records from backups or erase historical values because a link is null |
| Anonymized retained public source | Existing content reference/tombstone under DEC-014/018 | Only snapshots/copies already owned by requester | Never reverse anonymization, export recovery-only identity mappings or infer former ownership from attribution |
| Own outgoing share | Proposed anonymous recipient alias local to archive, acknowledgement/creation time | Own sharing configuration, if approved below | Raw recipient IDs/email/name or recipient list reached through a received share leaks another account's private data |

Opaque IDs are still linkable data, not anonymization. Retain non-user source
identifiers already present in owned records only as minimized provenance;
do not promise that a reference can be dereferenced later. A public URL alone
cannot preserve the requester's historical values. Conversely a bookmark does
not require a foreign recipe body to become portable.

Snapshot payloads need field-level projection, including nested imported
provenance and calculation traces. A raw snapshot dump could carry source
import IDs, URLs/query secrets, internal actors or later-added fields. Preserve
owned wording and factual provenance while excluding foreign private metadata.
This does not authorize rewriting user-entered text or new general redaction
semantics. Unclassifiable newly introduced fields require review before release.

## Common representative data shape

The following is an abbreviated proposed export projection, shared by options
A and C. IDs such as `rv1` are readable synthetic stand-ins for actual string
IDs/ULIDs. `content` below uses the current recipe snapshot field names; other
grouping names are proposed export vocabulary. It is a shape specimen, not an
exhaustive schema or full fixture; the completeness map defines the coverage
that each option must preserve.

```json
{
  "format": "vibedietr-account-export",
  "schema_version": "1.0.0",
  "account": {
    "id": "self", "name": "Example cook", "email": "cook@example.test",
    "email_verified_at": null, "timezone": "Europe/London",
    "public_profile": null
  },
  "recipes": [{
    "id": "recipe:41", "current_version_id": "rv2",
    "versions": [{
      "id": "rv1", "version_number": 1,
      "content": {
        "title": "Soup, lemon & herbs", "servings": "2.000000000000000000",
        "visibility": "private",
        "ingredients": [{
          "position": 1, "original_text": "1 pinch salt, optional",
          "quantity": "1.000000000000000000", "standard_unit": null,
          "custom_unit": "pinch", "generic_wording": "salt",
          "notes": null, "catalogue_match": null
        }],
        "sections": [{"key": "section-7", "position": 1, "name": "Finish"}],
        "steps": [{"position": 1, "text": "Stir.\nTaste before serving.", "section_key": "section-7"}],
        "imported_nutrition": null
      }
    }, {
      "id": "rv2", "version_number": 2,
      "content": {"title": "Soup with parsley", "servings": "3.000000000000000000"}
    }],
    "active_revision": null
  }],
  "plans": [{
    "id": "plan:8", "type": "dated", "visibility": "private",
    "days": [{
      "id": "day:2", "day_index": 1, "date": "2026-10-17",
      "slots": [{
        "id": "slot:3", "name": "Dinner", "position": 3,
        "recipe_entries": [{
          "id": "recipe-entry:9", "recipe_id": "recipe:41",
          "recipe_version_id": "rv1", "recipe_version_number": 1,
          "planned_servings": "1.500000000000000000",
          "recipe_snapshot": {"title": "Soup, lemon & herbs", "servings": "2.000000000000000000"},
          "nutrition_snapshot": {"source": "ingredient_estimate", "values": null}
        }],
        "item_entries": [{
          "id": "item-entry:10", "kind": "one_off",
          "planned_amount": "1.000000000000000000", "planned_unit": "serving",
          "catalogue_item_id": null, "one_off_wording": "My snack",
          "one_off_nutrition": null
        }]
      }]
    }]
  }],
  "diary": {
    "states": [{"id": "state:5", "entry_ref": "recipe-entry:9", "current_transition_id": "t5"}],
    "transitions": [
      {"id": "t1", "state_id": "state:5", "sequence": 1, "action": "consume", "predecessor_id": null, "target_transition_id": null, "consumption_snapshot_id": "s1", "actual_amount": "1.500000000000000000", "consumed_local_at": "2026-10-17T19:00:00", "timezone": "Europe/London", "utc_offset_minutes": 60, "consumed_at_utc": "2026-10-17T18:00:00Z", "effective_diary_date": "2026-10-17"},
      {"id": "t2", "sequence": 2, "action": "correct", "predecessor_id": "t1", "consumption_snapshot_id": "s1"},
      {"id": "t3", "sequence": 3, "action": "correct", "predecessor_id": "t2", "consumption_snapshot_id": "s2", "actual_amount": "1.250000000000000000"},
      {"id": "t4", "sequence": 4, "action": "reverse", "predecessor_id": "t3", "target_transition_id": "t3", "consumption_snapshot_id": null, "actual_amount": null, "consumed_at_utc": null},
      {"id": "t5", "sequence": 5, "action": "reconsume", "predecessor_id": "t4", "consumption_snapshot_id": "s3", "actual_amount": "2.000000000000000000"}
    ],
    "nutrition_snapshots": [
      {"id": "s1", "source_entry_ref": "recipe-entry:9", "source_version_id": "rv1", "actual_amount": "1.500000000000000000", "actual_unit": "serving", "nutrition_source": "ingredient_estimate", "is_estimate": true, "nutrition": null},
      {"id": "s2", "source_version_id": "rv1", "actual_amount": "1.250000000000000000", "actual_unit": "serving", "is_estimate": true, "nutrition": null},
      {"id": "s3", "source_version_id": "rv1", "actual_amount": "2.000000000000000000", "actual_unit": "serving", "is_estimate": true, "nutrition": null}
    ]
  },
  "targets": {
    "profiles": [{"id": "profile:6", "name": "Rest day", "is_default": true, "targets": [{"nutrient": "protein", "type": "range", "exact_value": null, "minimum_value": "80.000000000000000000", "maximum_value": "100.000000000000000000"}]}],
    "phases": [{"id": "phase:4", "plan_id": "plan:8", "nutrition_target_profile_id": null, "profile_name_snapshot": "Earlier profile", "starts_on": "2026-10-01", "ends_on": null, "values": [{"effective_on": "2026-10-01", "targets": {"protein": {"type": "minimum", "exact_value": null, "minimum_value": "75.000000000000000000", "maximum_value": null}}}]}]
  }
}
```

In a complete specimen every transition includes its state, recorded timestamp,
actual unit and applicable local/UTC fields; every recipe/entry snapshot includes
the complete projected content, not the shortened content above. Time-only `t2`
changes consumption time and reuses `s1`; reversal has no copied nutrition.
Ad-hoc diary entries use the same state/transition/snapshot graph with a
`diary-entry` reference instead of a plan entry.

Additional shape cases required by the domain, for every option:

```json
{
  "source_reference": {"kind": "recipe_version", "id": "foreign-rv7", "version_number": 7, "availability": "unavailable"},
  "catalogue_entry": {"catalogue_item_id": "catalogue:22", "catalogue_item_version_id": "cv3", "catalogue_item_version_number": 3, "planned_amount": "125.500000000000000000", "planned_unit": "gram", "catalogue_snapshot": {"name": "Oats", "brand": null}},
  "numeric_cases": [
    {"nutrient": "protein", "unit": "g", "amount": "12.123456789012345678", "meaning": "known"},
    {"nutrient": "sugars", "unit": "g", "amount": "0.000000000000000000", "meaning": "known"},
    {"nutrient": "fibre", "unit": "g", "amount": null, "meaning": "missing"},
    {"nutrient": "salt", "unit": "g", "amount": null, "meaning": "trace"}
  ],
  "bookmark": {"id": "bookmark:3", "recipe_id": "recipe:900", "availability": "unavailable"},
  "own_proposal": {"id": "proposal:2", "base_catalogue_item_version_id": "cv3", "reason": "Package now states 12 g protein", "state": "pending", "changes": [{"field": "protein", "before": "10", "after": "12"}]}
}
```

`numeric_cases`, `meaning`, and simplified proposal changes illustrate export
semantics, not new domain attributes. Full nutrition also preserves basis,
source limits/operators, derived-value basis, estimate/completeness/exclusions,
policy versions and match provenance as actually recorded. Include a unit and
nutrient dictionary covering all ten supported nutrients. Do not claim unknown
values are zero or invent omitted nutrient facts.

## Option A — Structured JSON download

Proposed file: `vibedietr-account-export-v1-<export-id>.json`, MIME
`application/json`. No archive. Top-level metadata plus account, recipes,
organisation, plans, diary, targets, imports and proposals; typed IDs join
cross-domain references. The common specimen above is its structure. Include
the field dictionary/schema as a documented companion reference, or embed the
schema object in the envelope for an entirely self-contained download.

- Strong generic language/parser portability and natural nested/null history.
  Large integers/decimals must be strings for common parser interoperability.
- Pretty printing aids a technical reader; a large diary is awkward to browse.
  A single file is easy to move but many generic consumers load it all at once.
- Producer can write arrays incrementally without loading the whole graph.
  It must still finish/validate the private file before publishing the download.
  Uncompressed text is likely larger than ZIP; no numeric size claim is made.
- Simplest packaging and one representation to maintain. Privacy requires the
  same allowlists as all alternatives; nesting does not confer authorization.
- Adding a human index later changes packaging; address it in format versioning.
  A syntactically readable JSON file is not a promised third-party import format.

## Option B — Normalized CSV tables in ZIP

Proposed archive:

```text
vibedietr-account-export-v1-<export-id>.zip
  manifest.json
  README.txt
  schema/csv-metadata.json
  data/account.csv
  data/recipes.csv
  data/recipe_versions.csv
  data/version_ingredients.csv
  data/version_sections.csv
  data/version_steps.csv
  data/recipe_nutrition.csv
  data/recipe_nutrition_history.csv
  data/organisation.csv
  data/memberships.csv
  data/bookmarks.csv
  data/lineage.csv
  data/plans.csv
  data/days.csv
  data/slots.csv
  data/recipe_entries.csv
  data/item_entries.csv
  data/snapshot_ingredients.csv
  data/snapshot_sections.csv
  data/snapshot_steps.csv
  data/nutrition_facts.csv
  data/diary_entries.csv
  data/consumption_states.csv
  data/transitions.csv
  data/consumption_snapshots.csv
  data/target_profiles.csv
  data/targets.csv
  data/target_phases.csv
  data/phase_values.csv
  data/imports.csv
  data/own_proposals.csv
  data/proposal_changes.csv
```

Representative joins (abbreviated columns, same synthetic records):

```csv
id,recipe_id,version_number,title,servings
rv1,recipe:41,1,"Soup, lemon & herbs",2.000000000000000000
rv2,recipe:41,2,Soup with parsley,3.000000000000000000
```

```csv
id,state_id,sequence,action,predecessor_id,target_transition_id,snapshot_id
t1,state:5,1,consume,\N,\N,s1
t2,state:5,2,correct,t1,\N,s1
t3,state:5,3,correct,t2,\N,s2
t4,state:5,4,reverse,t3,t3,\N
t5,state:5,5,reconsume,t4,\N,s3
```

```csv
id,plan_id,profile_id,profile_name_snapshot,starts_on,ends_on
phase:4,plan:8,\N,Earlier profile,2026-10-01,\N
```

Each version/entry snapshot has its own scope key; ingredient/section/step and
nutrition rows join to that scope, never to current live recipe content.
`phase_values` expands to rows per phase/effective date/nutrient. Dictionary
declares text IDs, decimal lexical forms, booleans, units, keys and references.
Proposed null codec: `\N` means null, empty field means empty string; escape
literal backslashes in string values before applying the null marker, and
document the reversible decoding. Quoting alone cannot distinguish null from
an empty string. A plain RFC 4180 reader does not implement this codec itself.

- Best for spreadsheet/dataframe users examining simple rows; no universal
  spreadsheet format can preserve all nesting and types without metadata.
- Highest mapping and evolution burden: many joins, composite scope keys,
  repeated nested structures, variable imported/provenance records. Extensible
  JSON-valued columns are viable but sacrifice the all-tabular advantage.
- Preserve precise decimal text, leading-zero IDs and date strings through
  typed import. Spreadsheet auto-detection can corrupt them. Formula-looking
  recipe text can execute when opened in spreadsheet software; CSV quoting
  does not prevent this. A reversible safe-text encoding and typed import
  instructions are needed; silently prefixing canonical wording changes data.
- Row streaming is natural; many temporary files/joins complicate completion
  validation and ZIP assembly. Table headers and keys cost bytes; compression
  helps repetition. Measure representative workloads before stating sizes.
- Privacy risks include accidental account/participant join tables and copied
  moderation fields. Normalization is no substitute for ownership checks.

## Option C — Documented JSON ZIP with human index (recommended)

Proposed archive:

```text
vibedietr-account-export-v1-<export-id>.zip
  manifest.json
  README.txt
  index.html
  schema/account-export-1.0.0.schema.json
  schema/data-dictionary.md
  data/account.json
  data/recipes.json
  data/organisation.json
  data/plans.json
  data/diary.json
  data/targets.json
  data/imports.json
  data/proposals.json
```

Each data file is `{ "schema_version": "1.0.0", "data": ... }` containing the
corresponding portion of the common specimen. Recipes include all owned
versions/revisions and nutrition histories. Plans contain complete entry-owned
snapshots even when the referenced live source is omitted. Diary includes
ad-hoc entries, states, every transition and every retained nutrition snapshot.
Cross-file references use typed IDs; no file-path-per-title layout is needed.

Proposed manifest shape (one file entry shown):

```json
{
  "format": "vibedietr-account-export",
  "schema_version": "1.0.0",
  "schema_path": "schema/account-export-1.0.0.schema.json",
  "export_id": "synthetic-export-01",
  "generated_at": "2026-10-18T12:00:00Z",
  "encoding": "UTF-8",
  "files": [{"path": "data/diary.json", "media_type": "application/json", "records": 5, "bytes": 1234}],
  "excluded_categories": ["credentials", "foreign_account_data", "transient_input_files", "restricted_operational_evidence"]
}
```

Counts/bytes above are illustrative, not measured. Define each file's counting
unit in the dictionary (diary count means transitions, for example); include
counts for contained record kinds separately where useful. A manifest may add
SHA-256 of final file bytes for accidental-corruption checks; hashes do not
authenticate the archive, and must not be presented as a signature.

Example offline human index:

```text
Your VibeDietr export — schema 1.0.0
Account → data/account.json
Recipes and versions → data/recipes.json
Plans and pinned snapshots → data/plans.json
Diary and corrections → data/diary.json
Targets and historical phase values → data/targets.json
What is included / excluded; units and estimate meanings; how IDs join;
how to open the files; no guaranteed third-party re-import or restore.
```

Proposed initial index is navigation, counts and explanations, not a second
full recipe book/diary report. `README.txt` duplicates the opening instructions
for users who cannot use HTML. HTML is static, accessible, escaped, script-free,
and has no remote assets, trackers or automatic source fetches. Raw import HTML
and user text must never become executable markup.

- Same natural nesting and precision as A; readable offline entry point and
  bundled schema/dictionary survive disappearance of an online documentation
  URL. ZIP extraction is an extra step for direct JSON consumers.
- More packaging work than A, far less normalization than B. Index and schema
  must be generated from the same approved projection to avoid discrepancies.
- Split files bound producer work and make browsing easier, but a long diary
  file can still be large. Incremental JSON-array generation is possible;
  splitting into numbered parts later is a packaging contract change, not an
  undocumented optimization. NDJSON could help streaming consumers later but
  should not silently replace JSON arrays.
- Compression should reduce repeated text. There are no measured archive-size,
  memory, timing or compression benchmarks yet. No new export library is needed
  merely to evaluate ZIP; local Sail reports `json`, `zip`, and `zlib` enabled.

## Packaging and implementation constraints for later DEP-07

Proposed conventions shared across viable designs:

- Fixed ASCII paths and application-generated opaque export IDs; no account
  email, recipe title, original upload filename, absolute path or `..` segment
  in filenames. Fixed paths avoid cross-platform filename and Unicode issues.
- UTF-8 text; JSON without BOM; LF for JSON/text, RFC 4180 quoting and CRLF for
  CSV. Preserve original stored text through escaping, not normalization.
- RFC 3339 UTC `Z` timestamps for instants, preserving stored fractional
  precision. Calendar dates stay `YYYY-MM-DD`. Consumption also retains local
  wall time, IANA timezone, offset and effective diary date: UTC alone loses
  its diary meaning, especially around DST. ZIP entry timestamps are not the
  authoritative history clock.
- String IDs, typed/scope-qualified keys, and exact decimal strings with `.`
  and no grouping/exponent; no display rounding in machine data. Own account
  references use `self`. Source recipe/catalogue version identity can remain
  provenance without including the referenced entity. IDs are scoped to the
  originating installation; this is not a promise of global identity or
  guaranteed future dereference. Never include foreign user IDs.
- Explicit null means a documented missing/not-applicable value; empty string
  remains text, zero remains known zero, empty arrays mean no records. A privacy
  omission has a documented reason/category, not a fabricated null source
  value. Optional absent fields are schema-defined; no silent truncation.
- Manifest lists schema, generation time, files/counts/byte lengths, exclusion
  categories and any understood omissions. Schema/README do not name foreign
  subjects in exclusions. A failed/incomplete export must not claim completeness.
- Do not add a general `files/` sweep. Current owned portable content is text,
  structured data and retained provenance; imports' private original/canonical
  files remain transient. Existing backups, receipts, audit evidence and other
  S3 objects are not account attachments. Future persistent binary attachments
  need an explicit domain rule and schema review.

ZIP generation is feasible with the current PHP extension, but production
readiness is not established by that fact. `ZipArchive::addFile` keeps source
files locked until close; preserve staged sources through successful archive
finalization and check all return/finalization results. Incremental source
writing avoids holding the whole graph in memory; ZIP assembly may still need
private temporary disk plus archive space and final private-object upload.
Streaming the completed object for download differs from generating a live ZIP
directly into an HTTP response. The latter complicates retries, expiry and
failure handling and is not recommended here.

Production uses private durable S3-compatible storage, database queues and
bounded workers ([configuration](PRODUCTION_CONFIGURATION.md),
[queue operations](QUEUE_OPERATIONS.md)). Current default worker timeout is
70 seconds, memory recycle threshold 256 MiB, container limit 384 MiB and
`retry_after` 90 seconds, with a required minimum 20-second margin. Large-export
runtime, temporary capacity, upload mode, ZIP64/large-file reader compatibility,
row consistency under concurrent edits and size limits need measured DEP-07
design/tests; no new queue or larger limits are approved by DEC-008. Record
generation boundaries accurately rather than promising a point-in-time backup.
Jobs must use scalar identifiers, correlated idempotent effects, explicit
queues, safe failures and inventory/monitoring under FND-09/DEP-04/DEP-05.

No password-protected ZIP is proposed: package encryption is separate from
the already-required owner-authenticated delivery and encrypted private
storage. Do not claim ZIP itself secures the exported content.

## Schema versioning choices and proposed rule

Viable approaches:

1. Integer schema edition (`1`, `2`): simple; any contract change creates a new
   edition, but does not communicate compatible extensions versus breaks.
2. Semantic `major.minor.patch`: communicates compatibility if precisely
   governed; slightly more release discipline. **Recommended.**
3. Date-based editions (`2026-10-18`): clear provenance but every consumer
   needs a separate compatibility map; a date alone conveys no safe upgrade.

Proposed initial release is `1.0.0`; specimen status does not publish it.
Manifest, file envelopes and bundled schema agree exactly; README/index state
the version and refer to the manifest. A local relative schema path makes the
package self-contained; JSON Schema's own `$schema` dialect URI identifies
Draft 2020-12, not VibeDietr's export version. Do not invent an online schema
service. Package naming's `v1` is only a hint; manifest is authoritative.

- **Major:** removed/renamed fields, changed meaning/type/unit/null or ID rules,
  new mandatory data, incompatible enum values, changed file layout/serialization
  or privacy representation. Changes still require product/privacy approval;
  bumping a version never authorizes extra data disclosure.
- **Minor:** documented optional additive fields/record collections only when
  old consumers can ignore them without changing existing interpretations.
- **Patch:** corrections to documentation/schema that do not change valid data
  or its meaning. A changed validity rule is not silently a patch.
- Keep previously released schemas/dictionaries; archive bytes and historical
  domain snapshots are never rewritten for a new schema. Validate each export
  against its exact bundled edition; consumers ignore documented optional
  additions, handle absent values and reject unsupported major versions.
- Recipe/catalogue version numbers, estimate/match policy versions and target
  effective dates are domain history, independent of export schema version.
  Preserve them explicitly. New schema versions do not imply supported
  in-app re-import, migration tooling or compatibility with every third party.

## Recommendation and owner choices

**Recommendation:** C: ZIP containing per-domain JSON, a manifest, bundled
versioned schema/dictionary, README and static navigation index. Use the
semantic-versioning rule above and exact decimal/string identifier conventions.
This preserves the existing nested historical model while providing an offline
starting point without maintaining parallel CSV or full human reports.

All options must meet the confirmed ownership boundary. Proposed conservative
privacy representation: materialize already-owned copies/snapshots, minimize
external recipe/catalogue provenance, export own submitted proposal fields
without restricted moderation evidence, and represent own outgoing share
recipients only with anonymous archive-local aliases and timestamps. Do not
include incoming foreign plan graphs, foreign account/profile fields,
credentials, restricted raw audit/operational evidence or transient binaries.
This is a proposed field projection, not a new right to traverse relationships.

Only these owner approvals are needed:

1. Choose A, B or C. For C, approve the navigation/counts/explanations index
   (rather than a complete human-readable recipe/diary report) and proposed
   packaging, precision, identifier and semantic-versioning rules together.
2. Approve the proposed privacy projection, particularly anonymous local aliases
   for recipients of outgoing shares, or prefer omitting recipient references
   entirely while retaining own share timestamps/acknowledgements. Neither
   choice permits foreign private account fields or foreign content expansion.

No format approval is inferred from silence. After answers, record the exact
approved contract and rationale in DEC-008, retain owner/status as instructed,
update reciprocal references, regenerate the context index as needed, run the
applicable final documentation gates once the decision stabilizes, verify
CURRENT_STATE remains at most 220 lines, review and commit the decision unit.

## External primary references

Accessed 2026-10-18; these establish technical constraints, not product rights.

- [RFC 8259](https://www.rfc-editor.org/rfc/rfc8259): JSON structure, UTF-8 and
  common number-interoperability limits support exact decimal/string IDs.
- [RFC 4180](https://www.rfc-editor.org/rfc/rfc4180): CSV quoting/newlines;
  it does not supply domain typing, nested history or null semantics.
- [W3C CSV metadata](https://www.w3.org/TR/tabular-metadata/): explicit column
  datatypes, null markers, primary and foreign keys can document option B.
- [OWASP CSV injection](https://owasp.org/www-community/attacks/CSV_Injection):
  formula evaluation risk applies to user-entered exported wording.
- [RFC 3339](https://www.rfc-editor.org/rfc/rfc3339): timestamp syntax;
  account timezone/local diary date remain additional domain data.
- [JSON Schema Draft 2020-12](https://json-schema.org/draft/2020-12): a viable
  bundled validation dialect, distinct from the application's schema version.
- [PHP ZipArchive addFile](https://www.php.net/manual/en/ziparchive.addfile.php):
  archive-source lifetime/locking considerations. Runtime module inspection
  confirmed local Sail ZIP support, not deployed production capacity.
