# DEP-09 privacy, retention and legal launch review

## Status and authority

Reviewed on 2026-11-19. **Incomplete; launch blocked. Owner approval pending.**
This is a repository evidence review and owner-review packet, not deployment
attestation or legal advice. No production installation, credentials, processor
contracts or external assessment artifacts were supplied. No launch approval is
recorded on the owner's behalf.

DEC-013 received an **owner-led legal-risk review, not professional legal
approval**. The approved status in [the retention schedule](AUDIT_RETENTION_SCHEDULE.md)
remains unchanged. Existing policy approval does not approve this launch record.
Seek specialist review for owner-identified high-risk rights/preservation/safety
requests or material scope changes. Reopen the review for advertising, payments,
subscriptions, incorporation, medical functionality, under-13 access, foreign
targeting, materially different public-content features, serious incidents or
relevant regulatory changes. Review the schedule annually as already required.

## Dependencies and evidence boundaries

`sail npm run context:task -- DEP-09` resolved no open-decision blocker.

| Dependency | Repository finding | Remaining gate |
| --- | --- | --- |
| DEP-07 / DEC-008 | Implemented version 1.0.0 JSON ZIP, schema/dictionary, owned historical snapshots, explicit nested projection, minimized provenance and archive-local outgoing recipient aliases. `AccountExportProjection`, `ExportContract` and `AccountExportTest` were inspected against the [approved contract](ACCOUNT_EXPORT_RESEARCH.md#approved-version-1-contract). Foreign received plans are not exported merely because readable; owned copies/snapshots remain owned. | [DEP-07 evidence](DEP_07_VERIFICATION.md) is conditional: actual private delivery, capacity, staging encryption/disposal, worker/scheduler, expiry, object versions and backup disposal need installation evidence. Format approval is not complete rights handling. |
| DEP-08 / DEC-012 | Implemented inactive/recovery/waiver/purge, under-13 operator command, protected journal, scoped holds and quarantined restore controls. [DEP-08 evidence](DEP_08_VERIFICATION.md) records local synthetic full/partial replay and disposal probes. | Provision and verify independent journal stores, actual copy inventory/retirement, keys, failure domains, restore release and operational alerts. Production evidence remains absent. |
| FND-05 / DEC-013 | `app/Audit` and `AuditEventStoreTest` / `AuditEventAuthorizationTest` provide append-only APIs, allowlisted fields/purposes, UTC identifiers, erasable identity mappings, external operators and integrity evidence. DEP-08 supplies retention pruning and separate evidence handling. Roadmap has no formal FND-05 completion status. | Do not infer whole-policy implementation from the ordinary audit foundation. Production encryption/access monitoring, complete rights searches and safety/case procedures require evidence. |
| DEC-010 | Decided. [Moderation policy](MODERATION_RESPONSE_POLICY.md) allows no numerical response commitments, harm-first then oldest-across-types review, starvation checks and bounded reasoned reconsideration. | Dedicated private webapp intake, restricted case records, safe responses, accountable operator, monitored contact and workable session schedule are unverified/unimplemented. Approval is not operation. |

## Findings and publication boundaries

| Area | Verified repository behavior | Discrepancy / launch requirement |
| --- | --- | --- |
| Privacy notice and terms | No complete installation-specific notice or terms, controller identity, dedicated contact email or policy-acceptance/version ledger was found in routes, registration or user schema. | Owner must review actual controller/contact, purposes/lawful bases, recipients/transfers, rights/complaint process, retention, children and terms. Decide which terms/version acceptance is required before implementing a ledger; do not treat a privacy notice as blanket consent. |
| Current public information | `/service-information` explains actual export/privacy boundaries, cookie/storage behavior, estimated nutrition, qualified purge timing and the pending launch review. Links appear on home, guest and authenticated layouts. | This is explicitly preliminary service information, not the missing final notice/terms. Approved DEC-012 notice template is not published as an operational promise. |
| Deletion | `AccountLifecycle` establishes an original 30-day deadline or immediate waiver/confirmed-under-13 deadline. Purge is processed asynchronously. Sole-administrator replacement is required. | The shared notice now says purge becomes due, rather than asserting completed deletion at the deadline. Independent copies, retained bookmarked public plans, anonymized recipes/catalogue and scoped evidence remain qualified. Identifying free text may need case-specific rights handling; removing attribution alone does not prove anonymity. |
| Rights | Profile export/deletion, authenticated recovery/waiver and protected case evidence primitives exist. Ordinary users cannot browse audit stores. | No complete monitored request intake/identity verification, cross-store search, redacted disclosure/correction/restriction/objection, external-processor follow-up or documented response/review process was verified. Export is not an exhaustive subject-access response. Owner must assign responsibility and approve the procedure against applicable rules. |
| Children | `accounts:purge-under13` handles confirmed accounts; public source suppression/purge is implemented. Registration currently validates only name/email/password. | Retention schedule section 10 describes required self-declared bands, under-13 registration denial and 13–17 information/safeguards; these are missing. No external DPIA/access/children/illegal-content assessment artifact was supplied. Do not describe these safeguards or assessments as verified. |
| Safety and moderation | Catalogue moderation, restricted decisions and bounded corrections exist; retained-plan suppression is a specific existing operation. | No general report intake, high-risk immediate hiding, safe author/reporter outcomes or accessible reconsideration workflow was found. The schedule's harmful-content terms and reporting safeguards require implementation and evidence, not new powers inferred from catalogue moderation. |
| Nutrition | Existing source/estimate presentation distinguishes calculated, imported and manual values. | Service information explicitly calls calculations estimates, warns of incomplete/inaccurate source values and disclaims medical advice. No accuracy guarantee or medical suitability is asserted. |
| Cookies | Laravel session and XSRF cookies; optional remembered-login cookie; browser-local theme storage. Local scripts/assets; no advertising/analytics integration found in current frontend. Production validation requires secure HttpOnly sessions and Lax/Strict SameSite. | HTTP and Chromium checks cover representative guest pages. Verify real TLS/proxy headers, remember-me expiry, authenticated flows, browser storage/network and any host-injected tracking on the deployed installation. Do not conclude consent/legal compliance from this scan. |
| Error logging | The initial policy test requested the catalogue while PHPUnit's catalogue cutover was disabled. Its 404 path exposed an existing `RedactSensitiveContext::__invoke` type mismatch: Laravel passes `Illuminate\Log\Logger`, but the tap requires `Monolog\Logger`. | The policy fixture now explicitly enables catalogue browsing. The logging defect was not changed or concealed as fixed; investigate and verify safe error logging separately before launch. |

## Retention reconciliation

Approved clocks below are policy, not invented installation measurements.
The [schedule](AUDIT_RETENTION_SCHEDULE.md) remains authoritative; its section 3
and some event rows are historical decision-date inventories, not a current
capability claim. DEP-08 now implements recovery/purge and retention tooling.

| Data | Approved clock / implemented boundary | Installation evidence needed |
| --- | --- | --- |
| Private account content | Active life plus optional recovery up to 30 days; waiver/confirmed-under-13 exceptions, final-purge rules and independently owned content boundaries apply. | Scheduler/worker/purge completion and external erasure, including failure paths. |
| Ordinary security / privileged identities | Six calendar months from security event; 12 calendar months for privileged accountability. `PruneAuditRetention` and `AuditErasure` implement original clocks and separate mappings. | Actual runs, restricted reads/exports, encryption and monitored expiry. |
| Moderation / provenance | Party identity up to 30 days after final decision or earlier purge; anonymous decisions 12 calendar months; active-version provenance plus 12 calendar months after supersession/withdrawal. | Purpose classification, final-decision/version clocks, safe case storage and deletion across all stores. Missing clock/integrity evidence must be investigated. |
| Exports | Seven calendar days from original creation, earlier final purge; excluded from ordinary recovery sets; cleanup and retention alerts exist. | Authenticated private download/expiry/delete drill; every version, stage and copy disposed on original clock. |
| Logs / failures / imports | Approved troubleshooting logs 14 days; failed-job pruning configured at 168 hours; transient import cleanup defaults 24 hours and abandoned OCR seven days. Retained private review content remains separate. | Classify each actual log/input layer; verify configured rotation, failed payload disposal, source cleanup and retained-content handling against the schedule, without dumping private payloads. |
| Recovery copies | At least daily capture; eligibility ends seven elapsed UTC days from original capture, prune within another 24 hours; normal affected-copy retirement target E + 8 days, conditional on successful operation/no dirty capture. | All databases, objects, off-host/manual copies, images, host/swap/staging layers; eligibility, physical disposal evidence, failure alerts and full/partial quarantine drills. |
| Protected erasure references | Linkable personal data retained only while affected copies remain; normal disposal target E + 9 days including storage/key generations. Anonymous receipt has its separate 12-month clock. | Verified complete inventory and retirement/cutover, independent journal integrity/freshness and incident/scoped-hold extensions. No unconditional physical destruction claim. |
| Holds and safety evidence | Necessary case-specific purpose/authority; review at least every 90 days, original clocks resume without restart. | Owner/controller authority, restricted access, legal-case validation, applicable statutory category and actual disposal. No general speculative hold. |
| Governance / exceptional incident records | Approved schedule: superseded safety assessments three calendar years from replacement; non-breach incident summaries 12 months after closure; breach summaries three years, identifiable evidence 12 months after closure or original six-month event expiry, whichever is later. Applicable CSEA evidence/report references have separate one-/five-year reporting clocks. | No complete governance/reporting/incident operational evidence supplied. Verify applicability, current rules, restricted stores and exact periods before handling a case; these are recorded policy categories, not a new legal determination or implemented general case system. |

## Processor/provider and recoverable-copy inventory to complete

This is a discovery inventory, not a list of contracted production processors.
No live configuration or secrets were read to manufacture deployment evidence.
For each selected entry record legal entity/role, service, data/purpose, contract
and processing terms, locations/transfers, subprocessors, access/encryption,
original retention clock, recoverable copies, rights/deletion/termination path,
accountable operator, evidence reference/date and verification outcome.

| Candidate / boundary | Repository evidence | Unknown before launch |
| --- | --- | --- |
| Host, DNS/TLS/reverse proxy, database/cache/session/queue, logs/metrics | Production configuration and observability/runbooks define controls. | Actual operator/providers, request/IP logs, locations, contracts, backups, telemetry/alert recipients and disposal. |
| Private objects, encrypted staging, off-host backup | Approved Docker reference: Garage, restic, rest-server and paired MySQL recovery sets. No versioning/Object Lock/provider expiry assumed. | Real failure domains, storage encryption/keys, every copy/version, host snapshots, retention jobs and recovery custody. |
| Protected journals and legal evidence | Separate primary/mirror, operator-only evidence and generation retirement services. | Provisioned independent stores, operator/observer separation, key/header/credential copies and retirement evidence. |
| Mail and security delivery | Mailpit is local; mail/service configuration offers transports. | Selected delivery provider, message/link/log retention, locations/subprocessors, monitored destination and manual contact coverage. Configuration options are not active contracts. |
| OpenFoodFacts | Read-only provider integration; factual catalogue provenance retained. | Deployed request metadata/contact user agent, provider role/terms and any personal data actually sent. Do not label every provider a processor automatically. |
| Import source websites / OCR | Local extraction/Tesseract; optional Google Document AI EU fallback disabled by default. | Whether production enables each workflow, egress/request metadata and source retention. If Google enabled: contracted entity, EU processor/location, credentials, provider retention/subprocessors and deletion evidence before notice publication. |
| Analytics / advertising / additional tools | None found in current frontend. | Host/proxy injection and any later integration; rerun storage/network review before activation. |

## Owner review and closure record

No new owner decisions or approvals received at preparation. The owner was asked
for identity/contact, operational responsibility/schedule, installation inventory
and external assessments. Do not interpret an absent answer as approval.

1. Confirm controller legal identity, site contact, UK-focused scope and actual
   purpose/lawful-basis records; review the final privacy notice and terms,
   including harmful-content rules, child-appropriate information and whether
   policy-version acceptance is required. These final documents are not ready
   for approval until the missing facts and workflows are resolved.
2. Name the privacy/moderation operator, monitoring/backup coverage, review
   session schedule and private manual response procedure under DEC-010.
   Supply a workable responsibility arrangement; no numerical SLA is requested
   or inferred. Implement and exercise the approved private webapp route.
3. Supply installation processor/copy/retention evidence and the external
   children/privacy/safety assessments assumed by DEC-013. Review residual risks
   after the missing age-band/report/safety safeguards are implemented.
4. Review the completed evidence and notices explicitly before closing DEP-09.
   Record approver, date, exact document/release versions, findings, accepted
   residual risks and unresolved conditions. Do not upgrade DEC-013 to
   professional legal approval or override an unmet technical launch gate.

Technical work does not require an owner to choose new retention periods or
reapprove DEC-008/010/012. Missing safeguards, private contact/report workflows,
final notice/terms and production verification remain acceptance-critical launch
blockers; this packet neither waives them nor claims DEP-09 complete.

## Current official guidance routing

Primary sources consulted on 2026-11-19: ICO [right to be informed](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/individual-rights/right-to-be-informed/)
and Ofcom [children's protection duties](https://www.ofcom.org.uk/online-safety/protecting-children/protection-of-children-duties-under-the-online-safety-act)
and [regulatory documents](https://www.ofcom.org.uk/online-safety/illegal-and-harmful-content/online-safety-regulatory-documents).
These route the owner to current transparency and service-specific safety
assessment requirements; applicability and adequacy need owner/specialist
assessment. This review makes no fresh legal conclusion from policy or tests.

## Verification

Historical DEP-07/08 results above are attributed to those records, not claimed
as new production evidence. New local checks use Sail and the separate testing
database; no development-account data was purged.

| Check | Result |
| --- | --- |
| `./vendor/bin/sail composer test` | Final full backend gate passed: 1,188 tests, 7,579 assertions, 389.96 seconds. |
| Focused export/audit dependency checks | 61 passed; the initial new policy fixture failed because catalogue browsing was disabled in PHPUnit. Explicit cutover configuration corrected that fixture. |
| Final focused policy/deletion presentation | Seven tests, 100 assertions passed via `sail artisan test tests/Feature/AccessibleFeedbackTest.php tests/Feature/ServiceInformationTest.php`. Covers public links/content, prohibited claims, qualified purge and session/CSRF cookie security attributes. |
| `sail npm run test:accessibility` | All 17 real Chromium tests passed, including guest cookie/local/session storage, optional remembered-login cookie, local script origins, public link keyboard access, both themes/mobile reflow, axe and existing pixel baselines. Mobile light/dark screenshots visually inspected. |
| `sail pint --test` / `sail composer analyse` | Passed after final PHP/test changes; 830 PHP files formatted, no static-analysis errors. |
| `sail npm run build` | Passed after final Blade changes. |
| `sail npm run docs:check` / `sail npm run docs:test` | Passed; all 20 documentation tests passed. Generated context index updated. CURRENT_STATE has 220 lines under the validator's split-line convention, including blanks/trailing line (219 newline-terminated lines). |
| Final diff/privacy review | Only DEP-09 presentation, tests and review/routing documentation staged; no credentials, production records, dependencies, migrations or configuration changes. |
| Remote CI | Not run for this unpublished local branch. All four required remote statuses remain required before merge; branch protection was not inspected. |
| Production/manual/owner review | Not verified. Real installation inventory/retention/rights/contact and external assessments, assistive technology and explicit owner approval remain open. Local tests do not satisfy these gates. |

The first full backend run completed with 1,187 passing tests and one existing
deletion-copy assertion failing; that assertion was updated to test the qualified
wording, then passed in the focused and final full runs. The first browser gate
could not overwrite two root-owned screenshots
created by the focused probe; the aborted test left a mobile viewport and the
run also observed a transient contrast issue during modal cancellation. Only
those two generated files had ownership corrected; the unchanged full browser
gate then passed. No assertion or visual baseline was weakened.

No policy-version acceptance gate is claimed: final terms, required acceptance
semantics and approved versions are missing. This remains a launch requirement
for owner review and subsequent implementation/testing where required. Schema,
deployment configuration, provider HTTP, queues and planning behavior were not
changed; migration, production-cache/readiness and specialized planning/scanner/
recovery checks are not applicable to this presentation/review unit. The full
backend gate still covers existing configuration and domain behavior.
