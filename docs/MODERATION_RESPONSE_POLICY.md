# Moderation response policy

## Approval and scope

The product owner explicitly approved the consolidated DEC-010 policy on
2026-11-19 after research, initial questions and follow-up clarification.
[DEC-010](DECISIONS.md#dec-010--moderation-escalation-and-service-levels) is the
decision record. This document supplies its approved detail and research.
Policy approval does not implement the contact workflow or establish launch
readiness. DEP-09 remains a separate review with other dependencies.

Scope covers pending manual submissions, duplicate candidates, factual
corrections, staged provider refreshes and disputed moderation decisions.
Existing catalogue states and administrator powers are unchanged.

## Confirmed requirements and implementation evidence

- [Product specification](PRODUCT_SPEC.md#manual-submissions): pending manual
  foods remain private to their submitter, with authorized moderator access.
  Administrators accept/reject staged changes. Rejection never silently replaces
  recipe matches; replacement requires owner confirmation or clearing.
- [Moderation](CATALOGUE_MODERATION.md): private queues and append-only decisions,
  bounded merges and eligible linked corrections exist. Current versions,
  provenance, historical snapshots and later owner choices have safeguards.
- [Corrections](CATALOGUE_CORRECTIONS.md) and
  [refreshes](CATALOGUE_PROVIDER_REFRESHES.md): whole-proposal acceptance creates
  a new version; rejection retains current facts. Stale acceptance needs explicit
  acknowledgement. Provider fetch failures preserve current data.
- `app/Domain/Catalogue/CatalogueModerationQueue.php` filters and paginates work
  by type/state. Manual, correction and candidate queues sort ascending by ID;
  refresh runs sort newest first. There is no cross-type priority/age queue.
- `CatalogueModeration::correctDecision` supports eligible manual/candidate/
  merge corrections, subject to later-state and reference checks. It is not a
  general appeal or reversal operation for correction/refresh outcomes.
  `CatalogueCorrectionModeration` reuses terminal decisions rather than reopening
  those proposals. A fresh proposal/refresh is the supported path where eligible.
- [Administrator security](ADMINISTRATOR_SECURITY_FOUNDATIONS.md) and
  [lifecycle](ADMINISTRATOR_LIFECYCLE.md): multiple administrators are supported,
  but technical eligibility is not evidence of staffing. Privileged decisions
  retain current capability, fresh authentication/factor and production-readiness
  requirements. Security notification infrastructure is not a moderation outcome
  or appeal notification system.
- [Audit rules](AUDIT_EVENTS.md) and the
  [retention schedule](AUDIT_RETENTION_SCHEDULE.md) apply. Private reasons,
  proposal diffs and notes do not belong in generic audit or operational logs.
  Final account purge and purpose-specific retention remain authoritative.
- Representative tests inspected: `CatalogueModerationQueueTest`,
  `CatalogueModerationTest`, `CatalogueCorrectionProposalTest` and
  `CatalogueProviderRefreshModerationTest` under `tests/Feature/Catalogue`.
  These evidence access/privacy, decision history, audit rollback and stale
  acceptance safeguards. Inspection is not a claim that tests were run here.

No dedicated appeal intake, user moderation outcome emails, priority assignment,
human-review overdue alerts or overdue workflow was found. Worker/provider
monitoring concerns technical jobs, not time spent awaiting human moderation.
The repository supplies no expected submission volumes, reviewer-hour budget,
review-duration measurements or backup coverage. Numerical review commitments
cannot be derived from the available evidence.

## Alternatives and rationale

| Approach | Operational implications and trade-offs |
| --- | --- |
| No published response targets initially | Avoids unsupported promises and has the lowest tracking burden, but gives users less timing certainty. Needs accountable manual oversight and clear explanations. Selected with risk/age prioritization and bounded reconsideration. |
| Targets differentiated by proposal type | Could improve predictability, but type alone does not determine risk or review effort. Requires workload/capacity evidence, clock definitions, coverage and missed-target tracking before defensible durations can be chosen. Not adopted initially. |
| Priority tiers with escalation/appeal routes | Supports risk-sensitive review and accountability, but introduces triage burden, priority disputes and starvation risk. Independent review requires actual coverage. Selected only as the simple ordering/reconsideration policy below, without numerical deadlines or owner arbitration. |

External guidance was consulted as useful practice, not binding requirements or
proof of compliance. The [Santa Clara Principles](https://santaclaraprinciples.org/)
support understandable reasons, notice and reconsideration in content moderation;
catalogue fact review is a different context. Google's
[SLO guidance](https://sre.google/workbook/implementing-slos/) supports measurable,
iteratively reviewed objectives and distinguishing objectives from commitments.
Neither establishes VibeDietr response times or staffing requirements.

## Approved operational rules

### Response expectations and prioritization

Launch without published response-time targets or numerical internal targets.
There is no guaranteed acknowledgement, decision or completion date. Internal
review procedures are operational expectations, not external time commitments.
Revisit numerical targets once volumes, item ages, review effort and available
administrator coverage provide evidence; adopting targets requires a new
explicit policy decision, including measurement and missed-target handling.

Review evidenced potential harm or materially incorrect shared catalogue data
first. Otherwise prioritize oldest pending work across all proposal types.
Check that ordinary work is not continually displaced. This is a manual review
procedure initially, not an implemented queue priority field or automatic sort.
Reviewing a concern grants no new emergency suppression or account powers.

### Reconsideration and settled decisions

An affected submitter, proposer or user may request one reasoned reconsideration
of a disputed decision on factual or procedural grounds. Further review requires
material new evidence or an identified review error. Repetition or administrator
disagreement alone does not reopen the matter. This policy sets no numerical
appeal deadline or response promise.

Prefer a different administrator for reconsideration. If none is available, the
original administrator may act; disclose that limitation in the response. Any
authorized administrator may uphold or supersede an earlier outcome within
existing safeguards. The latest valid recorded administrator decision settles
the matter, including when reconsideration overrides an earlier outcome. There
is no owner arbitration or new hierarchy of moderation powers.

Discretion remains within authorization, audit, privacy, versioning and
safe-reversal safeguards. A stale form, informal opinion or unsupported attempted
reversal is not a new valid decision. Do not edit or delete earlier history.
Reconsideration may uphold an outcome, produce a supported linked correction,
or lead to a fresh factual proposal or provider refresh. A pending follow-up
proposal does not itself override the current catalogue outcome. Reversal is
not guaranteed; unsupported reversals require separately scoped work rather
than bypassing guards, changing lifecycle fields directly or rewriting history.

### Ageing items and delays

During every moderation review session, check the oldest pending items across
submissions, corrections, staged refreshes and duplicate candidates. Record why
unresolved items remain pending and carry them forward for reassessment. There
is no fixed calendar frequency or numerical ageing threshold in this policy.
DEP-09 must confirm an accountable operator and workable review schedule before
launch; no staffing availability is assumed here.

Age alone never approves, rejects or deletes an item. Existing retention and
account-deletion rules continue to apply. A technically stale base version is
separate from queue age and still requires explicit review before acceptance.
A staged refresh retains its existing active-run restriction; age does not
release the key or justify replaying a terminal fetch job.

Handle responses manually initially, with a short outcome/reason or delay
explanation and next step. Exclude private administrator notes and other users'
private information. Delays are not missed numerical service commitments because
none are set initially. Do not promise a replacement date unless supportable.

## Approved user-facing wording

General notice:

> Catalogue changes require administrator review. Review times vary, and we do
> not currently promise a response date. A delay does not mean acceptance or
> rejection.

Delay update:

> Your submission is still awaiting review. We cannot currently give you a
> decision date. This delay does not mean it has been accepted or rejected.

Publish this wording with the implemented workflow after launch review; approval
of this text does not claim that the page or messaging already exists.

## Downstream implementation and launch requirements

- **DEP-09:** DEC-010 is no longer an open-decision blocker. Review moderation
  behavior and notices before launch, confirm the accountable operator, workable
  review schedule and monitored contact coverage, and verify the private webapp
  route and manual response procedure below. Other dependencies and privacy/legal
  review remain; this task does not complete DEP-09.
- **NUT-09:** Preserve existing decision/history and correction safeguards.
  Operational review must inspect oldest work across types despite the separate
  queue ordering, check starvation and record unresolved reasons privately.
- **NUT-10:** Preserve terminal proposals, explicit stale review and immutable
  versions. Reconsideration of factual outcomes may use a fresh eligible proposal;
  do not present the manual-decision correction operation as a general undo.
- **NUT-11:** Review staged refreshes for age despite newest-first display.
  Distinguish provider/worker failure recovery from human-review delay. Fresh
  refreshes retain current eligibility and active-run constraints.
- **FND-05:** Retain existing minimized decision accountability. Future contact
  or reconsideration history must use purpose-appropriate private records and
  the approved retention rules, not arbitrary free text in ordinary audit.
- **Future workflow work:** Build a dedicated private webapp page for
  reconsideration intake, restricted request records, access controls and a
  response mechanism. Plan a monitored manual procedure for reasoned outcomes,
  delay updates and disclosure when the original reviewer acts. These are
  requirements for future scoped implementation; no route, schema, notification,
  timer, new state or administrator power is added by this decision task.

Manual oversight needs a privacy-appropriate record for unresolved reasons and
follow-up; private administrator notes are not user replies or generic logs.
Existing notes are bounded and not uniformly available across proposal types;
they are not a complete contact/case-management system. Any future queued or
scheduled workflow must declare DEP-04/DEP-05 and meet the existing job inventory,
privacy, idempotency and operational conventions before enablement.
