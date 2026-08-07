# Product decision register

## Purpose and use

This register records product choices that `PRODUCT_SPEC.md` intentionally
leaves unresolved. It does not make those choices. Implementation must follow
the existing constraints below and wait where a backlog item is explicitly
marked `Blocked` by an open decision.

Decision statuses are:

- `Open`: the question is recorded but has not yet been assigned a research or
  owner-input step.
- `Research required`: technical, provider, operational, security, or legal
  investigation is needed before a choice can be made.
- `Owner input required`: the alternatives are sufficiently understood for the
  product owner to choose the intended behavior.
- `Decided`: the final decision and rationale have been recorded.
- `Superseded`: a later decision replaces this entry and identifies it.

Backlog relationships mean:

- `Blocked`: the backlog item's acceptance criteria cannot be completed without
  this decision.
- `Constrained`: work may proceed, but it must preserve options and must not
  select the unresolved behavior.
- `Related`: the item provides context, evidence, or follow-up work but does not
  depend on the decision.

## Register summary

| ID | Title | Status | Owner |
| --- | --- | --- | --- |
| DEC-001 | Food-matching confidence thresholds | Research required | Technical investigation |
| DEC-002 | Food-match review-warning treatment | Owner input required | Product owner |
| DEC-003 | Nutrient storage precision | Decided | Product owner |
| DEC-004 | Nutrient display precision | Decided | Product owner |
| DEC-005 | Recipe-import providers and formats | Research required | Technical investigation |
| DEC-006 | OCR providers and formats | Research required | Technical investigation |
| DEC-007 | Import and OCR extraction-quality thresholds | Research required | Technical investigation |
| DEC-008 | Account data-export format | Owner input required | Product owner |
| DEC-009 | Initial administrator assignment | Decided | Product owner |
| DEC-010 | Moderation escalation and service levels | Owner input required | Product owner |
| DEC-011 | Manual-food de-duplication and merge rules | Owner input required | Product owner |
| DEC-012 | Backup erasure timing | Research required | Technical investigation |
| DEC-013 | Security and legal audit retention | Decided | Product owner |
| DEC-014 | Public meal plans after owner deletion | Decided | Product owner |
| DEC-015 | Administrator second-factor mechanism and recovery | Research required | Technical investigation |
| DEC-016 | Administrator security-notification delivery | Research required | Technical investigation |
| DEC-017 | Culinary measurement jurisdictions | Decided | Product owner |

## DEC-001 — Food-matching confidence thresholds

- **Question requiring resolution:** What score defines the minimum selectable
  match, what score defines high confidence, and are any further confidence
  bands required?
- **Why it matters:** The thresholds determine whether a recipe line is matched
  automatically, selected but flagged for review, or left unmatched, which in
  turn affects estimate completeness and user trust.
- **Status:** Research required.
- **Owner:** Technical investigation.
- **Alternatives:** Fixed global thresholds; thresholds calibrated by matcher
  or catalogue version; thresholds that also vary by evidence or food class.
- **Existing constraints from `PRODUCT_SPEC.md`:** The highest-scoring candidate
  above a minimum sensible threshold is selected. High-confidence matches may
  be accepted without interruption, lower-confidence qualifying matches remain
  selected but reviewable, and sub-threshold candidates remain unmatched. The
  creator can replace an automatic match, and match evidence and provenance are
  retained.
- **Backlog relationships:** `Blocked`: NUT-12, NUT-13. `Constrained`: NUT-15,
  NUT-16, UX-04. `Related`: NUT-07.
- **Resolution condition:** Evaluate the intended ranking approach against
  representative recipe lines and catalogue candidates, document boundary
  outcomes and error trade-offs, then record approved threshold values and a
  versioning rule.
- **Final decision and rationale:** Unresolved.

## DEC-002 — Food-match review-warning treatment

- **Question requiring resolution:** How should lower-confidence selected
  matches be presented and reviewed across relevant screens?
- **Why it matters:** The treatment must make uncertain matches noticeable and
  correctable without implying that confidence is certainty or relying on
  color alone.
- **Status:** Owner input required.
- **Owner:** Product owner.
- **Alternatives:** Inline status and action; a review queue or summary with
  line-level detail; a combined summary and inline treatment. Specific visual
  styling remains part of the choice.
- **Existing constraints from `PRODUCT_SPEC.md`:** Lower-confidence qualifying
  matches stay selected but must be clearly flagged for review. The creator
  must be able to replace them easily. An orange outline or tooltip is only an
  example, not a requirement. Partial estimates must identify review-needed
  lines, and suggested claims cannot be presented as verified when nutrition is
  incomplete.
- **Backlog relationships:** `Blocked`: UX-04. `Constrained`: NUT-16.
  `Related`: UX-02.
- **Resolution condition:** Review accessible interaction proposals for every
  confidence state and record the chosen warning, review, and correction flow.
- **Final decision and rationale:** Unresolved.

## DEC-003 — Nutrient storage precision

- **Question requiring resolution:** What numeric representation and precision
  should be retained for each supported nutrient and for derived kcal/kJ
  values?
- **Why it matters:** Insufficient precision destroys source data and can
  accumulate calculation error, while an undefined representation makes
  imports, manual values, calculations, and comparisons inconsistent.
- **Status:** Decided.
- **Owner:** Product owner.
- **Alternatives:** A common fixed scale; nutrient-specific fixed scales;
  source-value preservation plus a normalized calculation representation.
- **Existing constraints from `PRODUCT_SPEC.md`:** Stored values retain useful
  source precision and must not be destructively rounded for display. Nutrition
  basis, units, source, provenance, and version must remain identifiable. Kcal
  is authoritative on energy conflict and conversion uses `1 kcal = 4.184 kJ`.
- **Backlog relationships:** Resolution unblocks FND-06, STB-06, and NUT-05
  and removes DEC-003 as their open-decision dependency. The recorded behavior
  constrains STB-04, NUT-15, and NUT-17. `Related`: DEC-004.
- **Resolution condition:** Inspect representative provider precision and
  calculation ranges, test round trips and aggregate error, and document the
  approved representation, scale, and conversion-rounding rules.
- **Final decision and rationale:** Nutrient values use exact base-10 decimal
  arithmetic. Normalized nutrient amounts are stored as MySQL
  `DECIMAL(38,18)` or PostgreSQL `NUMERIC(38,18)`, using the same precision and
  scale for every nutrient. Application code passes decimal strings or decimal
  value objects and must not convert nutrient values to PHP floats or database
  floating-point types. Values are non-negative and are validated before they
  reach the database so database-specific implicit conversion does not select
  the result.

  Each versioned nutrient value identifies its nutrient, canonical amount and
  unit, basis, value status, origin, provenance, normalization-policy version,
  and any derivation. Energy uses kcal as its canonical calculation unit.
  Nutrients measured by mass use grams as their canonical calculation unit.
  Per-100-g, per-100-ml, per-serving, whole-recipe, and any future bases remain
  explicit rather than being inferred from a field name. A future nutrient
  that is not a mass or energy quantity requires an explicitly registered
  canonical unit.

  Preserving the numeric meaning and provenance of an imported value is
  required; reproducing its lexical formatting is not. Numeric source
  observations retain their original unit, basis, qualifier, provider,
  provider record/version, and import provenance where those facts are needed
  to explain normalization or a conflict. Insignificant trailing zeros, the
  original JSON field layout, and a byte-for-byte provider response need not be
  retained solely for nutrient precision. Canonical exports may therefore use
  the normalized value, unit, basis, status, provenance, and version instead of
  reconstructing the provider's formatting.

  Imported and manually entered values use the same representation and
  precision rules, differing through their provenance. Provider-imported
  observations are immutable. A correction, manual override, provider refresh,
  or changed normalization policy creates a new attributable version instead
  of rewriting an earlier observation. Published catalogue values, recipe
  nutrition versions, and consumption snapshots retain the exact canonical
  value and normalization policy that applied when they were created.

  Calculations use the full stored precision and arbitrary-precision decimal
  arithmetic with at least 50 significant working digits. Addition and
  multiplication do not round intermediate results. Division retains at least
  24 fractional guard digits until a result is persisted or displayed. A
  persisted result is quantized once to scale 18 using decimal round-half-up.
  An imported value with more than 18 fractional digits is also normalized
  round-half-up, and that precision reduction is recorded rather than left to
  implicit database rounding. Display rounding occurs only on the final value
  and follows DEC-004; it never changes stored data. Canonical exports contain
  the full stored value, with insignificant trailing zeros optionally omitted,
  unless an export contract explicitly requires display-style rounding.

  Kcal is the sole normalized energy value used for calculations. KJ is not a
  second independently editable normalized value; it is derived with exactly
  `1 kcal = 4.184 kJ`. When only kJ is supplied, canonical kcal is calculated
  as `kJ / 4.184` at working precision and quantized only at the storage
  boundary. When kcal is supplied, it is canonical. If supplied kcal and kJ
  conflict, both numeric source observations and the conflict provenance are
  retained, kcal remains canonical, and application kJ is recalculated from
  kcal. KJ conversion is otherwise rounded only for display or an explicitly
  rounded export.

  Known zero, missing, trace, below-limit, approximate, and
  not-significant-source values remain distinct. Known zero stores an amount
  of zero with known status. Missing stores a null amount with missing status.
  Trace or below-limit data stores a null exact amount plus its status and,
  where supplied, a comparison modifier and exact normalized threshold. It is
  displayed as the quantified limit, such as `<0.03 mg`, or as `Trace` when no
  limit is available, never as zero. Because a trace value has no exact point
  amount, calculations do not silently substitute zero, half the limit, or the
  limit; totals instead retain a trace/uncertainty indication and may carry a
  separately calculated upper bound.

  This common high-scale exact representation is deliberately more generous
  than current provider data. It avoids binary floating-point and aggregate
  error, preserves very small micronutrient values, keeps storage independent
  from nutrient-specific display precision, works conventionally in Laravel,
  MySQL, and PostgreSQL, and allows materially higher-precision future
  providers without an expected schema change. The additional decimal storage
  and arithmetic cost is accepted as modest for this workload. Existing values
  already destructively rounded by the application cannot have their lost
  digits invented during migration; any recovery from retained OpenFoodFacts
  data must be explicit and provenance-aware.

## DEC-004 — Nutrient display precision

- **Question requiring resolution:** What display precision and rounding rule
  applies to every supported nutrient in each relevant context?
- **Why it matters:** Consistent sensible rounding avoids false precision while
  ensuring users can compare recipe, plan, diary, and target values.
- **Status:** Decided.
- **Owner:** Product owner.
- **Alternatives:** One product-wide table; context-specific precision where a
  documented reason exists; adaptive display with defined minimum and maximum
  precision.
- **Existing constraints from `PRODUCT_SPEC.md`:** Display rounding is
  nutrient-specific and does not change stored data. Confirmed examples are
  whole kcal, one decimal place for fat, and two decimal places for salt. Kcal
  is the preferred energy display.
- **Backlog relationships:** Resolution unblocks FND-06 and STB-06 and removes
  DEC-004 as their open-decision dependency. The recorded behavior constrains
  NUT-16, PLAN-12, and UX-04. `Related`: NUT-05.
- **Resolution condition:** Record an approved table covering kcal, kJ, fat,
  saturated fat, carbohydrates, sugars, fibre, protein, salt, and sodium,
  including tie-breaking and any context-specific exceptions.
- **Final decision and rationale:** All user-facing nutrition values use one
  product-wide, nutrient-specific display table. The same precision applies to
  catalogue foods and ingredients, whole recipes, recipe servings, diary and
  meal-plan totals, nutrition targets, comparisons, and human-readable reports.
  Calculations and aggregation always use full stored precision and round only
  the final value for display. Display values are never written back to stored
  nutrient data.

  | Nutrient | Preferred display unit | Decimal places | Smallest ordinary positive display | Known positive value below rounding resolution |
  | --- | --- | ---: | --- | --- |
  | Energy | kcal | 0 | `1 kcal` | `<1 kcal` |
  | Energy | kJ | 0 | `1 kJ` | `<1 kJ` |
  | Protein | g | 1 | `0.1 g` | `<0.1 g` |
  | Fat | g | 1 | `0.1 g` | `<0.1 g` |
  | Saturated fat | g | 1 | `0.1 g` | `<0.1 g` |
  | Carbohydrate | g | 1 | `0.1 g` | `<0.1 g` |
  | Sugars | g | 1 | `0.1 g` | `<0.1 g` |
  | Fibre | g | 1 | `0.1 g` | `<0.1 g` |
  | Salt | g | 2 | `0.01 g` | `<0.01 g` |
  | Sodium | mg | 0 | `1 mg` | `<1 mg` |

  Kcal is the primary energy display. Salt is the primary UK-facing
  salt-related nutrient. Sodium is shown only when explicitly available,
  targeted, or requested; salt and sodium are not automatically shown together.
  This display decision does not authorize deriving a missing salt value from
  sodium or a missing sodium value from salt.

  Final display values use exact decimal round-half-up. For example, `12.24 g`
  displays as `12.2 g`, `12.25 g` displays as `12.3 g`, and `0.345 g` of salt
  displays as `0.35 g`. This familiar, deterministic rule also matches
  DEC-003's storage-boundary tie-breaking rule. Banker's rounding is less
  predictable to users, while truncation, floor, and ceiling introduce
  directional bias.

  Known zero displays as numeric zero at the nutrient's fixed precision, such
  as `0 kcal`, `0.0 g`, or `0.00 g`. A known positive amount that would round
  to zero displays the quantified resolution limit from the table. A source
  below-limit value retains its normalized limit, such as `<0.03 mg`, even when
  that needs more decimal places than an ordinary value. An unquantified
  source-declared trace value displays as `Trace`. Missing or unsupplied data
  displays as `Not available`, never as zero. `Unknown` is not used as a generic
  synonym for missing, and `Not a significant source` remains source detail
  rather than being converted to zero.

  Machine-readable exports preserve the full stored values, units, bases,
  statuses, provenance, and versions required by DEC-003. Human-readable
  exported reports use the UI display table; accompanying machine-readable
  data retains full precision. Advanced users do not receive a screen-level
  option for more decimals: full precision is export-only. DEC-004 does not
  select the export packaging or file format, which remains DEC-008.

  Locale changes decimal and grouping separators only, not units, precision,
  or rounding. Accessible presentation keeps units visibly and programmatically
  associated with values, uses semantic table headings where applicable, gives
  less-than values unambiguous assistive-technology wording, preserves
  meaningful trailing zeros, aligns comparison values clearly, and never relies
  on color alone. Accessibility does not change numerical precision.

  Fixed precision keeps recipe, plan, diary, and target comparisons stable and
  avoids arbitrary magnitude-dependent changes. Nutrient-specific precision
  avoids false precision for energy and recipe estimates while retaining useful
  macro, salt, and sodium differences. Explicit zero, trace, below-limit, and
  missing treatments preserve the semantic distinctions required by DEC-003.

## DEC-005 — Recipe-import providers and formats

- **Question requiring resolution:** Which provider or in-application approach
  will extract recipe structure from each non-OCR import route, and which
  webpage and document formats will be supported initially?
- **Why it matters:** Provider capabilities, licensing, privacy, failure modes,
  and format coverage shape the import architecture and production
  configuration.
- **Status:** Research required.
- **Owner:** Technical investigation.
- **Alternatives:** In-application extraction; one external provider; a layered
  approach using structured webpage data with a provider or local fallback.
  Initial supported formats may be a documented subset of candidate formats.
- **Existing constraints from `PRODUCT_SPEC.md`:** Import channels are webpage
  URL, pasted text, uploaded document, and photograph or scan. Every import
  begins as a private draft, preserves wording and provenance, requires user
  review, and cannot be planned before finalization. Uploaded extraction inputs
  are transient and deleted after processing.
- **Backlog relationships:** `Blocked`: REC-16, DEP-02. `Constrained`: REC-15,
  REC-17, DEP-03. `Related`: FND-09, UX-06.
- **Resolution condition:** Compare viable approaches using representative
  sources, document supported and unsupported formats, privacy and data-flow
  implications, operational limits, costs, and failure behavior, then approve
  the initial provider/approach and format matrix.
- **Final decision and rationale:** Unresolved.

## DEC-006 — OCR providers and formats

- **Question requiring resolution:** Which OCR provider or in-application
  approach will be used, and which document and image formats will be accepted
  for OCR initially?
- **Why it matters:** OCR choice determines extraction capability, privacy,
  upload validation, production configuration, cost, and the quality evidence
  available to the review workflow.
- **Status:** Research required.
- **Owner:** Technical investigation.
- **Alternatives:** Local OCR; one external OCR provider; a primary provider
  with a documented fallback. Initial image/document formats may be a
  documented subset of candidate formats.
- **Existing constraints from `PRODUCT_SPEC.md`:** Photograph, scan, and
  uploaded-document imports create private drafts and require review. Original
  wording and source provenance are retained. Uploads are transient extraction
  inputs, not recipe attachments, and are deleted after processing.
- **Backlog relationships:** `Blocked`: REC-17, DEP-02. `Constrained`: DEP-03.
  `Related`: FND-09, UX-06.
- **Resolution condition:** Benchmark viable OCR approaches and format support
  on representative inputs; document privacy, retention, cost, reliability,
  language, and operational constraints; then approve the initial provider and
  format matrix.
- **Final decision and rationale:** Unresolved.

## DEC-007 — Import and OCR extraction-quality thresholds

- **Question requiring resolution:** What quality or confidence levels allow
  extracted content into a reviewable draft, require stronger warnings, or
  cause the import to fail?
- **Why it matters:** Without defined boundaries, the product may silently
  accept unusable structure, reject recoverable content, or present provider
  confidence inconsistently.
- **Status:** Research required.
- **Owner:** Technical investigation.
- **Alternatives:** One overall threshold; field-specific thresholds; tiers
  combining confidence with completeness and mandatory user review.
- **Existing constraints from `PRODUCT_SPEC.md`:** Imports never publish
  automatically, always require review, retain original wording and
  provenance, and may not be used in plans while drafts. Uncertain parsing and
  incomplete nutrition remain visible rather than being silently guessed.
- **Backlog relationships:** `Blocked`: REC-16, REC-17. `Constrained`: REC-15,
  UX-06. `Related`: FND-09.
- **Resolution condition:** Establish representative extraction benchmarks,
  define measurable boundary outcomes for whole imports and important fields,
  and record the approved failure, warning, and review behavior.
- **Final decision and rationale:** Unresolved.

## DEC-008 — Account data-export format

- **Question requiring resolution:** In what file structure and serialization
  format should the self-service account export be delivered?
- **Why it matters:** The choice affects portability, readability, versioning,
  archive generation, download cleanup, and the risk of including data the
  requester does not own.
- **Status:** Owner input required.
- **Owner:** Product owner.
- **Alternatives:** A structured JSON export; tabular files in an archive; a
  documented archive containing both machine-readable data and a human-readable
  index.
- **Existing constraints from `PRODUCT_SPEC.md`:** The export includes the
  requester's account data, recipes, plans, diary history, and targets. It must
  not include another user's private account data merely because shared content
  is viewable.
- **Backlog relationships:** `Blocked`: DEP-07. `Constrained`: FND-09, DEP-04.
  `Related`: DEP-09.
- **Resolution condition:** Approve a versioned export schema and packaging
  format after reviewing representative complete data, portability, readability,
  privacy boundaries, and future compatibility.
- **Final decision and rationale:** Unresolved.

## DEC-009 — Initial administrator assignment

- **Question requiring resolution:** How is the first administrator selected
  and assigned safely in a new environment?
- **Why it matters:** Moderation cannot operate without an administrator, while
  an unsafe bootstrap path could allow privilege escalation or accidental
  assignment.
- **Status:** Decided.
- **Owner:** Product owner.
- **Alternatives:** A one-time deployment/bootstrap command; an explicitly
  configured account promoted through a protected process; a manual operational
  procedure with auditable verification.
- **Existing constraints from `PRODUCT_SPEC.md`:** Administrators moderate
  manual-food submissions, corrections, and OpenFoodFacts refreshes. Ordinary
  users cannot directly edit or delete barcode-imported shared records. The
  specification intentionally leaves administrator assignment, escalation,
  and moderation service-level rules undefined.
- **Backlog relationships:** Resolution unblocks FND-04 and removes DEC-009 as
  its open-decision blocker. The recorded behavior constrains FND-05, FND-13,
  FND-14, NUT-09, NUT-10, NUT-11, REC-13, DEP-02, and DEP-08. `Related`:
  FND-03, FND-11, FND-12.
- **Resolution condition:** Record the approved bootstrap actor, verification
  steps, environment behavior, audit evidence, and recovery path, including how
  repeat assignment is prevented or controlled.
- **Final decision and rationale:** Initial production administrator assignment
  is an explicit, one-time command-line operation performed by one individually
  authenticated and traceable trusted deployment or host operator. Mandatory
  approval from a second operator is not required. The target user cannot
  initiate or request elevation, and administrator status is never assigned by
  registration order, login, profile input, a web bootstrap endpoint, a
  remotely redeemed bootstrap token, or another automatic path.

  Production deployment configuration must explicitly enable bootstrap and
  identify the permitted target account. Configuration constrains the command;
  it never triggers promotion and must be disabled or removed after success.
  The target must already be an active account, must not be in account-deletion
  recovery, must have a verified email that exactly matches the configured
  identifier, and must have an approved second factor enrolled. Email
  verification may remain optional for ordinary users but is mandatory for
  administrators. Initial bootstrap and break-glass recovery are activated only
  by the operator's CLI procedure; the target cannot complete either through a
  web action. The second-factor requirement is decided, but factor/provider
  selection, enrollment and recovery implementation are deferred to DEC-015.

  Bootstrap is permitted only when no administrator exists and a separate,
  persistent bootstrap-completed marker has never been set. Before changing
  state, the operation must confirm the intended environment, explicit
  enablement, exact target match, target eligibility and verification, zero
  administrators, the unset marker, operator confirmation, and successful
  audit persistence. It must recheck the mutable preconditions and atomically
  assign the role, write the application audit evidence, and set the completion
  marker. A mismatch, concurrent attempt, repeat attempt, or unavailable audit
  write fails closed. Deleting, revoking, compromising, or losing an
  administrator never clears the marker or reopens bootstrap.

  Every successful or refused bootstrap attempt records a UTC timestamp,
  environment and application instance, immutable target user identifier and
  verification identifier, traceable operator or deployment identity,
  correlation identifier, observed administrator/marker state, confirmation of
  the configured target match, previous and resulting privilege state,
  command/application version, and outcome or refusal reason. A successful
  production event has linked application and external deployment/operations
  evidence. Passwords, second-factor codes, recovery material, bootstrap
  secrets, and other credentials are never recorded. Exact audit access,
  retention, and later anonymization remain governed by DEC-013.

  Multiple active administrators are supported but are not mandatory. Routine
  promotion is initiated only by an existing active administrator after recent
  re-authentication and entry of a valid second-factor code. The target must be
  active, have verified email and an enrolled second factor, and then accept the
  pending promotion while authenticated and using their own second factor. A
  pending promotion grants no privilege and expires after 24 hours. Any active
  administrator may cancel it with recent re-authentication and a second factor;
  the target may decline it with their own authenticated second factor. Initial
  bootstrap and break-glass recovery are the only exceptions to target
  acceptance. Ordinary users cannot originate a promotion, so accepting an
  authorized pending promotion is not self-promotion.

  An active administrator may revoke another administrator only after recent
  re-authentication and entry of a valid second-factor code, and only when at
  least one other active administrator will remain. Administrators cannot
  revoke themselves through the ordinary workflow. Revocation immediately
  invalidates the affected account's sessions and privileged credentials; the
  account remains an ordinary user unless separately disabled or deleted and
  must authenticate again. The sole active administrator cannot request account
  deletion or lose administrator status through normal workflows; a replacement
  must be active first.

  Normal password, account, and second-factor recovery is attempted before
  operational recovery. If no administrator is usable, the trusted operator may
  use a separate break-glass CLI procedure which does not clear or reuse initial
  bootstrap state. It requires explicit recovery configuration identifying a
  replacement that meets the same active-account, verified-email, and enrolled-
  second-factor requirements; applies the same fail-closed audit and evidence
  rules; and may atomically activate the replacement and revoke a compromised
  administrator without ever leaving zero active administrators.

  Promotion initiation, acceptance, decline, cancellation, expiry, revocation,
  and break-glass recovery are audited and generate security notifications to
  the target and active administrators. A reliable production notification
  channel is required before these workflows are enabled; channel/provider
  selection is deferred to DEC-016. Local development retains CLI-only
  bootstrap, no automatic assignment or self-promotion, the bootstrap marker,
  zero-administrator and last-administrator safeguards, and application audit
  behavior when exercising the real workflow. It may omit external deployment
  evidence and use explicit test/factory administrator states. The normal
  database seeder must not silently create an administrator, and local/test
  shortcuts must be unavailable in production.

  This outcome uses an existing operational trust boundary rather than public
  registration, prevents first-registrant and unverified-email takeover,
  prevents accidental or repeated bootstrap, avoids an avoidable zero-admin
  state, and supplies an auditable recovery path without treating recovery as a
  new installation. It deliberately leaves provider selection and audit
  retention to their separately tracked investigations rather than weakening
  the approved controls.

## DEC-010 — Moderation escalation and service levels

- **Question requiring resolution:** What escalation path and service-level
  expectations apply to pending submissions, corrections, refreshes, and
  disputed moderation decisions?
- **Why it matters:** These rules affect user expectations, operational load,
  stale pending data, and production policy wording, but are not necessary to
  model the basic moderation states.
- **Status:** Owner input required.
- **Owner:** Product owner.
- **Alternatives:** No published response target initially; target response
  times by proposal type; priority tiers with an escalation or appeal route.
- **Existing constraints from `PRODUCT_SPEC.md`:** Administrators accept or
  reject staged changes, decisions are auditable, pending manual foods remain
  private to their submitter, rejected changes do not silently replace recipe
  matches, and moderation behavior must be reviewed before launch.
- **Backlog relationships:** `Blocked`: DEP-09. `Constrained`: NUT-09, NUT-10,
  NUT-11. `Related`: FND-05.
- **Resolution condition:** Approve and document response expectations,
  prioritization, escalation or appeal behavior, stale-item handling, and the
  user-facing wording for unmet targets.
- **Final decision and rationale:** Unresolved.

## DEC-011 — Manual-food de-duplication and merge rules

- **Question requiring resolution:** How are suspected duplicate manual
  non-barcode catalogue records detected, reviewed, approved, rejected, or
  merged, and what happens to dependent references?
- **Why it matters:** Over-aggressive merging can corrupt food identity and
  recipe estimates, while no duplicate handling can fragment the shared
  catalogue.
- **Status:** Owner input required.
- **Owner:** Product owner.
- **Alternatives:** Warn and allow separate submissions; prevent submission
  above defined identity criteria; create a moderator-reviewed duplicate or
  merge workflow that preserves aliases and references.
- **Existing constraints from `PRODUCT_SPEC.md`:** Manual non-barcode foods are
  rare, begin pending, remain private to the submitter until approved, and are
  moderated. Rejection must not silently replace a recipe line's pending item.
  Catalogue provenance and versions are retained, and existing user data must
  not be silently merged or removed.
- **Backlog relationships:** `Blocked`: NUT-08. `Constrained`: NUT-02, NUT-09.
  `Related`: FND-02, NUT-03.
- **Resolution condition:** Approve identity evidence, candidate-detection
  boundaries, moderator and submitter actions, reference migration behavior,
  provenance requirements, and reversal or correction handling.
- **Final decision and rationale:** Unresolved.

## DEC-012 — Backup erasure timing

- **Question requiring resolution:** When and how does permanently erased
  account data age out of backups, and how is it protected from ordinary
  restoration in the meantime?
- **Why it matters:** The live-data 30-day recovery rule does not by itself
  define immutable backup retention, restoration safeguards, or the time at
  which erased personal data ceases to exist in recoverable copies.
- **Status:** Research required.
- **Owner:** Technical investigation.
- **Alternatives:** Backup expiry on a documented fixed schedule; backup
  expiry by tier with restore-time re-erasure controls; another provider- and
  policy-supported erasure design.
- **Existing constraints from `PRODUCT_SPEC.md`:** Account deletion is
  recoverable for 30 days, after which specified private data is permanently
  removed and specified public/shared contributions are anonymized. Actual
  retention and erasure behavior must be documented, applicable GDPR rights
  supported, and legal compliance must not be claimed from technical design
  alone.
- **Backlog relationships:** `Blocked`: DEP-06, DEP-08, DEP-09. `Constrained`:
  FND-02, FND-09, DEP-04. `Related`: DEP-07.
- **Resolution condition:** Investigate intended hosting and backup facilities,
  restoration flows, applicable rights and advice, then approve documented
  retention periods, restore-time controls, verification evidence, and user
  wording.
- **Final decision and rationale:** Unresolved.

## DEC-013 — Security and legal audit retention

- **Question requiring resolution:** Which audit data, if any, must remain
  after account erasure for a narrowly defined security or legal purpose, and
  for how long?
- **Why it matters:** Retaining too much personal data conflicts with
  minimization and erasure, while removing required evidence may create
  security, operational, or legal risk.
- **Status:** Decided.
- **Owner:** Product owner.
- **Alternatives:** Anonymize all audit actors at purge; retain a minimal subset
  for fixed purpose-specific periods; retain separately protected evidence only
  when a documented obligation applies; combine all three as a layered policy.
- **Existing constraints from `PRODUCT_SPEC.md`:** Audit data contains only the
  personal data necessary for its stated purpose and follows the documented
  retention policy. Administrator decisions, catalogue provenance, recipe
  overrides and versions, snapshots, and anonymization events require an
  auditable history. Security, audit, and legally required retention details
  require pre-launch review.
- **Backlog relationships:** Resolution unblocks FND-05 and removes DEC-013 as
  an open-decision blocker from DEP-08 and DEP-09. The recorded policy
  constrains FND-05, DEP-06, DEP-08, DEP-09, NUT-09, NUT-10, and NUT-11.
- **Resolution condition:** Inventory audit-event purposes and personal fields,
  complete the technical security review and the chosen owner-led legal-risk
  review, and approve a purpose-specific retention, access, anonymization, and
  deletion schedule without representing it as professional legal approval.
- **Final decision and rationale:** Adopt the combined layered policy in
  `AUDIT_RETENTION_SCHEDULE.md`. Ordinary application audit events contain
  allowlisted, purpose-specific metadata. Domain versions and snapshots remain
  product data rather than copies in audit payloads. Security, moderation,
  user-visible activity, operational logs, and legally protected evidence have
  separate access, fields, stores, and clocks. Ordinary audit excludes
  credentials, raw IP addresses, full user agents, arbitrary model snapshots,
  and private recipe, plan, diary, target, OCR, import, or export content.

  The core periods are six calendar months from an ordinary security event;
  12 months for identifiable privileged-operator events; 30 days after final
  decision for identifiable moderation/report/appeal parties followed by 12
  months for the anonymous decision; and the life of an affected catalogue or
  public-content version plus 12 months after supersession/withdrawal for
  anonymous provenance. User-visible activity is available for up to six
  months but is deleted at final purge.

  Private recipes, versions, nutrition overrides, plans, diary data, targets,
  and snapshots are user content, not protected audit evidence. They survive
  active account life and an optional recovery period of up to 30 days, then
  are deleted. An authenticated user may waive recovery and request immediate
  final purge, subject to the sole-administrator safeguard and a documented
  hold. A confirmed under-13 account bypasses recovery and its public recipes
  and plans are hidden and deleted. Final purge destroys ordinary audit
  identity mappings, irreversibly anonymizes surviving provenance, and leaves
  a random non-derived purge receipt for 12 months.

  Operational periods are 14 days for redacted non-security application/error
  logs; seven days after final failure/resolution for failed-job metadata;
  seven days for export archives and credentials; deletion within 24 hours for
  terminal OCR/import source files; seven days for abandoned uploads; 30 days
  for minimized terminal import/OCR metadata; and 12 months for non-personal
  migration, rebuild, backfill, and purge evidence.

  A non-breach security-incident summary remains for 12 months after closure.
  A personal-data-breach summary remains for three years after closure, while
  identifiable evidence remains for 12 months after closure or its normal
  six-month event period, whichever is later. Current online-safety records
  remain current; superseded versions remain for three years. Applicable CSEA
  evidence remains in a separate protected store for one year from reporting
  and its report reference for five years. A valid Ofcom deceased-child notice
  uses exactly the information and period stated.

  Holds are evidence-specific, documented, access-restricted, and reviewed at
  least every 90 days. They do not reset the normal clock. Backup data is
  beyond operational use and completed purges must be replayed before a restore
  returns to service; the explicit backup lifecycle remains DEC-012.

  Security and moderation access are separated, ordinary users receive only a
  filtered activity view, and protected legal evidence is not stored in the
  ordinary FND-05 event store. Retained stores require append-only application
  writes, reliable UTC timestamps, tamper/integrity protection, encryption,
  monitored access/exports, and verifiable deletion. External processors
  require a reviewed inventory before production use.

  The United Kingdom is the primary launch jurisdiction. Public recipes and
  meal plans are conservatively treated as potentially regulated user-to-user
  functionality. Registration uses self-declared age bands and rejects
  declared under-13 users. The owner asserts that an external children/privacy/
  online-safety DPIA has been completed; no repository artifact verifies it.
  High-risk reports hide content pending review, and authors/reporters receive
  an outcome and one reconsideration route. Assessments are reviewed annually
  and after significant change or serious incident.

  The owner-led basis assessment proposes legitimate interests for ordinary
  security, moderation, provenance, and optional deletion recovery. Legal
  obligation is used only for an identified applicable provision, notice, or
  order. Special-category and criminal-offence material requires its additional
  applicable condition and separate protection. The privacy notice identifies
  the owner's legal name operating as VibeDietr and a dedicated contact email.

  Primary UK sources were accessed on 31 July 2026 and are catalogued in
  `AUDIT_RETENTION_SCHEDULE.md`. NCSC's six-month baseline is a security
  recommendation, not law. Ofcom's three-year superseded-record baseline is
  regulator guidance. CSEA periods apply only where those rules apply. The
  six-year simple-contract limitation period is explicitly rejected as a
  blanket reason to retain audit events.

  FND-05 is security-approved subject to retention classification, field
  allowlisting/secret rejection, removable actor mappings, append-only and
  tamper-evident storage, role separation, and separate statutory-evidence
  handling. The owner considered professional legal review disproportionate
  for the initial personal project and completed an owner-led legal-risk
  review. This decision is not legal advice and must not be called legally
  approved. Review is mandatory before monetization, incorporation, payments,
  medical functionality, under-13 access, foreign-market targeting, or a
  materially different public-content feature.

## DEC-014 — Public meal plans after owner deletion

- **Question requiring resolution:** When a meal-plan owner completes account
  deletion, is each public plan removed or retained with anonymized
  attribution?
- **Why it matters:** The choice changes public availability, attribution,
  snapshot retention, copied-plan behavior, privacy wording, and purge logic.
- **Status:** Decided.
- **Owner:** Product owner.
- **Alternatives:** Remove the public plan; retain it with anonymized
  former-user attribution; let the owner choose during deletion, subject to a
  defined default.
- **Existing constraints from `PRODUCT_SPEC.md`:** Non-public plans are removed
  after the 30-day recovery period. Public recipes remain anonymized, approved
  catalogue contributions remain without an identifying submitter, and
  independent plan copies owned by other users are unaffected. Public plans
  are read-only to viewers and cannot expose private recipes.
- **Backlog relationships:** Resolution unblocks FND-03 and removes DEC-014 as
  a blocker from DEP-08. The recorded behavior constrains FND-02, PLAN-08,
  PLAN-09, and DEP-08. `Related`: DEP-09.
- **Resolution condition:** Approve one deletion outcome and document its
  attribution, snapshot, share/link, copy, purge, recovery, and user-notice
  behavior.
- **Final decision and rationale:** Public-plan handling is automatic; the
  deleting owner is not offered a choice. From the moment account deletion is
  requested, public plans behave publicly as if the account had already been
  deleted. A public plan with at least one active bookmark owned by another
  user is retained at its existing URL, immediately unlisted, and attributed
  only through the non-linked label `Former VibeDietr user`. A public plan
  with no other-user bookmarks immediately becomes unavailable. New bookmarks
  cannot be added after deletion is requested. A retained plan is removed when
  its final bookmark is removed, including after final account purge. Broader
  inactivity-based retention is a separate future policy question.

  Existing public-link shares remain valid only for a retained bookmarked
  plan. Authenticated viewers may continue making independent private copies;
  those copies do not count as bookmarks and remain unaffected by source-plan
  deletion, account recovery, or purge. A retained plan preserves only the
  pinned recipe snapshots required to display and copy the plan. Those
  legitimately public snapshots remain stable, while links to live recipes
  work only while the current recipes are independently public. Retention must
  not expose live private recipes, diary or consumption data, targets, private
  one-off items, personal organisation, account metadata, or any field that
  was not authorized for public presentation. If the complete plan cannot be
  proven public-safe, the entire plan becomes unavailable; privacy protection
  takes priority over availability.

  During the 30-day recovery period, the original ownership and attribution
  remain only in protected recovery state. Secure account restoration restores
  original ownership and attribution, previously unavailable zero-bookmark
  plans, and their prior visibility and discovery state. After 30 days, the
  recovery-only links and unavailable plans are permanently removed; a
  retained anonymized plan cannot be reclaimed or reattributed. Administrators
  may exceptionally suppress or permanently remove a retained plan for
  privacy, security, legal, or moderation reasons, but may not restore,
  reattribute, transfer, or extend recovery for it. Normal account recovery is
  the only route to reattribution for content that has not been permanently
  removed.

  The approved plan-specific account-deletion notice is:

  > Your account will become inactive immediately, and your name and profile
  > will be removed from your public meal plans. Public plans bookmarked by
  > another user will remain available through existing links as unlisted plans
  > attributed to “Former VibeDietr user.” They may still be copied but cannot
  > receive new bookmarks, and will be deleted when their final bookmark is
  > removed. Other meal plans will become unavailable immediately. Copies
  > already owned by other users are unaffected. If you restore your account
  > within 30 days, your plans, ownership, attribution, and previous visibility
  > will be restored. After 30 days, deleted plans cannot be recovered and
  > retained plans cannot be reclaimed.

  The approved retained-plan notice is:

  > This unlisted public plan was created by a former VibeDietr user and remains
  > available because it is bookmarked. It cannot receive new bookmarks, but
  > signed-in users may create an independent copy. It will be removed when no
  > bookmarks remain. Recipe details are preserved snapshots; links to current
  > recipes may be unavailable.

  This outcome retains a plan only where another user has demonstrated ongoing
  interest, preserves existing links and independent copying, and minimizes
  orphaned data through immediate anonymization, unlisting, disabled new
  bookmarks, whole-plan fail-closed validation, and deletion after the final
  bookmark. It supports data minimization and transparent privacy wording but
  does not claim that technical behavior alone guarantees legal compliance.

## DEC-015 — Administrator second-factor mechanism and recovery

- **Question requiring resolution:** Which second-factor code mechanism and
  provider will satisfy the administrator activation, promotion, revocation,
  acceptance, and recovery requirements recorded in DEC-009?
- **Why it matters:** Administrator status cannot become active and privileged
  lifecycle actions cannot proceed in production until the required second
  factor can be enrolled, verified, recovered safely, and tested without a
  password-only fallback.
- **Status:** Research required.
- **Owner:** Technical investigation.
- **Alternatives:** An application-supported time-based code mechanism; codes
  supplied through an external identity or authentication provider; another
  reviewed code-based factor with separately protected recovery material.
- **Existing constraints from DEC-009:** Every administrator must enroll an
  approved second factor before activation. Routine promotion and revocation
  require the acting administrator's valid code; routine acceptance and decline
  require the target's valid code. Initial bootstrap and break-glass recovery
  are CLI-only but still require confirmed prior enrollment for the target.
  Password-only fallback is prohibited, codes and recovery material are never
  logged, and production fails closed until the mechanism exists. Local/test
  states must be explicit and unavailable in production.
- **Backlog relationships:** `Blocked`: FND-13, FND-14. `Constrained`: DEP-02,
  DEP-08. `Related`: FND-11.
- **Resolution condition:** Evaluate viable mechanisms and providers for code
  security, enrollment, account and factor recovery, replay protection, rate
  limits, secrets handling, deployment configuration, self-hosted operation,
  testability, accessibility, cost, and provider failure; then record the
  approved mechanism, recovery rules, and production/local behavior.
- **Final decision and rationale:** Unresolved.

## DEC-016 — Administrator security-notification delivery

- **Question requiring resolution:** Which production channel or provider will
  reliably deliver the administrator security notifications required by
  DEC-009?
- **Why it matters:** Privilege changes and break-glass recovery require prompt,
  independently observable notification, while the current mail configuration
  only logs messages and is not a production delivery service.
- **Status:** Research required.
- **Owner:** Technical investigation.
- **Alternatives:** A production transactional-email provider; an external
  security-notification service; a layered design combining in-application and
  independently delivered operational notifications.
- **Existing constraints from DEC-009:** Promotion initiation, acceptance,
  decline, cancellation, expiry, revocation, and break-glass recovery notify the
  target and active administrators. Production must have at least one reliable
  configured channel before these workflows are enabled. Notification events
  are correlated with audit evidence and must not contain credentials,
  second-factor codes, recovery material, or other secrets. Local development
  may use an explicit non-delivery test channel.
- **Backlog relationships:** `Blocked`: FND-13, FND-14. `Constrained`: DEP-02,
  DEP-08. `Related`: FND-12.
- **Resolution condition:** Evaluate viable channels and providers for delivery
  assurance, identity and destination verification, failure and retry behavior,
  self-hosted operation, privacy, secrets handling, observability,
  accessibility, cost, local testing, and incident recovery; then record the
  approved channel, fallback behavior, and production enablement checks.
- **Final decision and rationale:** Unresolved.

## DEC-017 — Culinary measurement jurisdictions

- **Question requiring resolution:** Which exact jurisdiction and factors apply
  to ambiguous culinary volume units and ounce/pound mass units?
- **Why it matters:** Teaspoons, tablespoons, fluid ounces, cups, pints, quarts,
  and gallons differ between metric culinary, UK imperial, and US customary
  systems. Same-dimension conversion cannot be safe or reversible until each
  short identifier has one product meaning.
- **Status:** Decided.
- **Owner:** Product owner.
- **Alternatives:** Metric culinary volumes; UK imperial volumes; US customary
  volumes; explicit jurisdiction-qualified units; or treating every ambiguous
  culinary label as custom and non-convertible.
- **Existing constraints from `PRODUCT_SPEC.md`:** Original quantity/unit text
  is preserved; standard same-dimension conversion is permitted; custom units
  remain valid; and the application must never invent food-dependent mass-to-
  volume conversion. FND-06 must not silently mix measurement systems or
  normalize an ambiguous alias.
- **Backlog relationships:** Resolution unblocks FND-06. It constrains STB-04,
  REC-02, REC-08, NUT-04, NUT-14, and later import mapping. `Related`: DEC-003.
- **Resolution condition:** The product owner identifies the system used for
  every currently supported ambiguous culinary unit, after which exact factors
  and unsafe aliases are recorded and tested.
- **Final decision and rationale:** Teaspoon (`tsp`) and tablespoon (`tbsp`)
  use the modern UK recipe convention and are exactly `5 ml` and `15 ml`.
  These meanings are selected for UK-facing recipe use even though historical
  household spoon capacities and older imperial references vary.

  Fluid ounce (`fl oz`), cup (`cup`), liquid pint (`pt`), liquid quart (`qt`),
  and liquid gallon (`gal`) always mean US customary liquid volume. The exact
  canonical factors are `29.5735295625 ml`, `236.5882365 ml`, `473.176473 ml`,
  `946.352946 ml`, and `3785.411784 ml`, derived from the exact US gallon of
  231 international cubic inches. These identifiers never silently mean UK
  imperial or US dry volume.

  Ounce (`oz`) and pound (`lb`) mean international avoirdupois mass and are
  exactly `28.349523125 g` and `453.59237 g`. `oz` is mass; fluid volume must
  use the explicit `fl oz` form.

  Full names, safe plurals, case variants, and punctuation variants may
  normalize where the result remains unambiguous. Single-letter `T` and `t`
  are not accepted as spoon aliases because case normalization could change
  their meaning. Unknown or jurisdiction-qualified alternatives remain custom
  units with their original text and no conversion factor.

  This mixed convention reflects the owner's direction that spoons in this
  UK-facing product should follow UK recipe practice while the other listed
  units normally originate in US recipes. Making the short identifiers
  deterministic is preferred to silent locale-dependent conversion. No part
  of this decision authorizes mass/volume, density, packing, shape, count-to-
  weight, or edible-portion assumptions.

## Manual validation checklist

The repository has no configured Markdown linter, link checker, documentation
test command, or CI workflow suitable for a lightweight check without adding a
new dependency or an unusual custom test system. Automated documentation
validation is therefore recorded as future roadmap item FND-10.

Before accepting a change to this register, manually confirm:

- Every intentionally deferred bullet in `PRODUCT_SPEC.md` maps to one or more
  stable decision IDs, including warning treatment, extraction thresholds,
  moderation escalation/service levels, and narrow audit-retention exceptions.
- Every entry contains the required question, importance, allowed status,
  owner, alternatives, existing constraints, categorized backlog relationships,
  resolution condition, and final decision/rationale field.
- Decision IDs are unique and remain stable when titles or wording change.
- Every `Blocked` roadmap dependency uses the matching decision ID; constrained
  or related work is not represented as a blocking dependency.
- No entry contradicts a confirmed rule in `PRODUCT_SPEC.md`, and every open
  entry says `Unresolved` rather than selecting an alternative.
- Markdown headings, tables, inline code, and links render correctly.
- A register change outside FND-01 records only a genuinely resolved or newly
  discovered product decision required by that task.
