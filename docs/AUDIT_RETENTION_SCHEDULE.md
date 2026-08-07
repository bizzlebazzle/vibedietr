# Audit retention schedule

## 1. Status and authority

This schedule records the approved product and technical policy for DEC-013.
It was security-reviewed as a technical design and completed through an
owner-led legal-risk review on 31 July 2026.

It is not legal advice and must not be described as professionally or legally
approved. The owner decided that professional legal review is not proportionate
for the initial personal project and accepts that residual risk. The policy
therefore uses the status:

> Product decision made — security reviewed — owner legal-risk review
> completed; professional legal review not performed.

The United Kingdom is the confirmed primary launch jurisdiction. Registration
is technically available worldwide, but the service is designed and operated
for a UK audience and is not deliberately marketed or localized for other
countries. Rights or obligations arising in a user's location are not excluded
and have not been comprehensively assessed.

The policy must be reviewed before advertising, subscriptions, payments, an
incorporated operator, medical functionality, under-13 access, foreign-market
targeting, or materially different public-content features are introduced.

## 2. Policy model

VibeDietr uses a layered, purpose-specific model:

1. Ordinary application audit events contain allowlisted metadata and expire
   on the schedule below.
2. User content and immutable product versions remain domain data, not copies
   embedded in audit payloads.
3. Shared-catalogue and qualifying public-content provenance can outlive an
   account, but the former contributor is irreversibly anonymized at final
   purge unless a documented hold applies.
4. Security, moderation, and user-visible histories have separate access and
   field rules.
5. Legally protected evidence is held in a separate incident/evidence store,
   not the ordinary FND-05 audit store.
6. A documented, scoped hold can suspend normal deletion only for the evidence
   and period that remain necessary.

Retention for a speculative future dispute or request is prohibited. A generic
limitation period is not a reason to retain every event.

## 3. Existing implementation inventory

The following describes repository behavior at the date of this decision. It
does not imply that each item is an approved audit source.

| Information | Status | Current location and access | Personal or sensitive fields | Decision treatment |
| --- | --- | --- | --- | --- |
| Login throttle state | Currently implemented | Application cache; used by authentication flow | Lower-cased email and raw IP contribute to a short-lived key | Operational anti-abuse state, not durable audit. Raw IP is not copied into ordinary audit events. |
| Authentication framework signals | Currently implemented | Runtime events only; no repository audit subscriber or durable audit table found | Account identity and event context may be available at runtime | FND-05 may persist only the allowlisted event fields in this schedule. |
| Database sessions | Currently implemented and configured locally | `sessions` table; no ordinary-user audit viewer | Session ID, nullable user ID, IP address, full user agent, payload, last activity | Operational session state, not audit history. Delete with expiry/account purge and do not duplicate into FND-05. |
| Password reset state | Currently implemented | `password_reset_tokens` table | Email, token, creation timestamp | Operational credential state. Never audit the token or reset link; remove through expiry/purge. |
| User and ingredient records | Currently implemented | Application database under owner authorization | Name, email, credentials, ingredient text and nutrition data | Current domain state, not history. Existing account deletion is an immediate hard delete until DEP-08 replaces it. |
| Laravel application, error, and local mail logs | Currently implemented locally | `storage/logs`; filesystem/operations access, no application viewer | Exceptions can expose SQL values, session/user identifiers, addresses, links, stack traces, and mail content | Not an approved audit store. Production ingestion must redact prohibited fields and apply the 14-day troubleshooting period. |
| Database queue and failed-job tables | Currently implemented infrastructure | Application database; operations access | Serialized payloads, exception text, job identifiers, timestamps | Operational state, not audit. Apply the failed-job schedule and remove personal payloads promptly. |
| Browser scanner warnings | Currently implemented client behavior | Browser console only | Potentially user-entered scanner values | Not a server audit source. Do not promote console output into durable audit. |
| Web-server, cloud-platform, database-audit, WAF, and external-provider logs | Not found | No production configuration or provider inventory found in the repository | Unknown | Inventory and classify before production. No source inherits a retention period merely because it is a log. |
| Application audit-event store, incident register, audit viewer, retention job, and hold mechanism | FND-05 ordinary audit store implemented; incident register, viewer, retention job, and hold mechanism remain planned/not found | `audit_events` plus separately erasable `audit_actor_identities`; no production viewer | Allowlisted fields defined below; HMAC integrity checking and application-layer append-only writes implemented | Future access views, monitored exports, expiry/anonymization jobs, protected evidence, encryption-at-rest verification, and holds must still follow this schedule. |

## 4. Audit and provenance event inventory

`Personal fields` below are maximum allowlists, not required fields. A category
must omit any field it does not need for its stated purpose.

| Event category | Status and trigger | Purpose | Actor, subject, and resource | Personal and potentially sensitive fields | Integrity and post-anonymization value | References |
| --- | --- | --- | --- | --- | --- | --- |
| Authentication and account security | Planned durable events triggered by login outcome, lockout, recovery, credential/verification change, session misuse, or suspected attack | Account security; abuse prevention; incident detection and investigation | Account/system actor; audit subject; authentication/session resource | Audit-subject reference while link is needed; event code; method; outcome; structured reason; UTC timestamp; anonymous correlation/session reference. No raw IP, full user agent, secret, token, link, cookie, or body | Append-only and reliably ordered. Correlation remains useful after the account mapping is destroyed | DEC-009, DEC-013; FND-05, FND-14, DEP-08 |
| Privileged-access lifecycle | Planned; bootstrap, promotion, acceptance, refusal, cancellation, expiry, revocation, break-glass, and denied attempt | Privileged-access accountability; incident investigation | External/operator or administrator actor; target account; environment/instance | Operator and target references, action, prior/new role state, outcome, UTC timestamp, correlation ID, structured reason. No credentials or raw IP | Actor must remain resolvable for the approved accountability period; role-only history remains useful later | DEC-009, DEC-013, DEC-015, DEC-016; FND-05, FND-14 |
| Account deletion, recovery, purge, and anonymization | Planned; deletion request, cancellation, recovery, waiver, purge job, or identity severance | Recovery; erasure workflow; deletion-job verification | Account actor/subject during recovery; purge system; affected account/resources | Request ID, account mapping until purge, state, deadlines, job/correlation ID, outcome, timestamps. No deleted payload | Identifiable through recovery; random non-derived purge receipt proves job completion without preserving identity | DEC-012, DEC-013, DEC-014; DEP-08 |
| Data export | Planned; request, generation, download, expiry, deletion | Account security; user-visible history; export accountability | Requesting account and export job; export resource | Subject reference, event code, outcome, timestamps, correlation ID. No archive, content, signed URL, or download credential | Event remains useful for detecting unauthorized export; content does not | DEC-008, DEC-013; DEP-07, FND-09 |
| Catalogue sources, imports, refreshes, versions, and matching | Planned; source import/version, OpenFoodFacts refresh, match decision, or catalogue rebuild | Shared-catalogue provenance; source traceability; correction review | System/operator; catalogue item/version and source | Source/version reference, external record reference, affected resource/version IDs, method/confidence where applicable, outcome, timestamp | Normally non-personal after contributor anonymization; immutable provenance remains useful | DEC-001 through DEC-004, DEC-013; NUT roadmap items |
| Catalogue submissions, corrections, vocabulary, and moderation | Planned; submit, approve, reject, correct, deprecate, report, decide, appeal, or complaint | Moderation accountability; public safety; shared-catalogue provenance | Contributor/reporter/author, moderator, affected resource/version | Temporary party references, moderator operator reference, decision code, structured reason, timestamps, version reference. Free text is exceptional and redacted | Decision provenance remains useful after contributor identity removal; moderator identity expires separately | DEC-009, DEC-010, DEC-011, DEC-013; FND-05, NUT-09 through NUT-11, REC-13 |
| Recipe versions, overrides, remix lineage, sharing, and publication | Planned domain history triggered by revision, override, remix, visibility change, share, or publication | Product history; restoration; public-content provenance | Recipe owner/system; recipe/version/lineage resource | Domain version references, action, visibility state, timestamp, outcome. Do not copy recipe bodies, ingredient text, or nutrition payloads into audit | Private history has no protected audit value after purge. Anonymous public lineage can remain useful | DEC-012, DEC-013; REC and NUT roadmap items, DEP-08 |
| Meal plans, targets, consumption, snapshots, sharing, bookmarks, and copies | Planned domain history triggered by edit, snapshot, consume/correct, share/publication, bookmark, or copy | Product history; stable snapshots; user-visible history; public-content provenance | Plan owner/system; plan/version/snapshot/copy resource | Domain version references, action, visibility, timestamp, outcome. No plan, target, diary, or health-revealing content in audit | Private history is deleted at purge. Approved anonymous public/copy provenance can remain useful | DEC-012, DEC-013, DEC-014; PLAN roadmap items, DEP-08 |
| Import and OCR processing | Planned; upload, processing attempt, success/failure, abandonment, cleanup | Operational troubleshooting; user-visible import status; verified transient deletion | Importing user/job; upload/import/draft resource | Subject and file references while needed, processor/version, event code, outcome, error class, timestamps, correlation ID. No raw file, extracted text, private content, or identity-bearing path | Minimized processing metadata has short-lived troubleshooting value; deletion event can verify cleanup | DEC-005 through DEC-007, DEC-012, DEC-013; FND-09, REC-15 through REC-17 |
| Security incidents and personal-data breaches | Planned; investigation opened, evidence attached, notification decision, remediation, closure | Incident investigation; breach documentation; lessons learned | Security operator/system; incident and affected systems/subjects | Case ID, type, facts, effects, remediation, decision, timestamps, minimized subject references, evidence references. Avoid bulk log copies | Minimized summaries remain useful after personal evidence expires | DEC-013; DEP-09 |
| Online-safety assessments and safety-measure records | Planned regulatory/governance records; assessment, adoption, change, review | Illegal-content and children's-risk assessment; evidence of safety measures and review | Owner as responsible/approving person; service/version | Responsible and approving operator identity, service version, evidence, findings, risk levels, controls, dates. No routine user content or identifiers | Current and historic organizational records remain useful and are required by the adopted Ofcom baseline | DEC-013; DEP-09 and future moderation work |
| CSEA report evidence and report reference | Planned exceptional legally protected record when detected content is reportable | Statutory reporting and preservation | Authorized security/safety operator; reported content/users; report resource | Only fields required by applicable law, which may include content, user/account data and available IP. Report reference is retained separately | Must remain identifiable and evidential for the statutory period; it is not ordinary audit data | DEC-013; future online-safety/incident work |
| Operational application/infrastructure logs | Application logs currently exist; other sources not found. Triggered by request, job, error, platform, database, or security-monitoring activity | Operational troubleshooting or a separately selected security question | Service/system and, only where necessary, pseudonymous subject | UTC time, environment/service, route template, status/error class, correlation ID. Prohibited fields are listed below | Troubleshooting value is short-lived. Only selected security events belong in six-month storage | DEC-013; DEP-04, DEP-05, DEP-09 |
| Failed jobs | Infrastructure exists; final job failure/resolution | Retry and operational troubleshooting | Application job/system; affected workflow | Job type, state, timestamps, correlation ID and minimized error class. Serialized personal payload is not audit evidence | Little value after retry/resolution; personal payload increases risk | DEC-013; DEP-04, DEP-05 |
| Migration, backfill, rebuild, and purge jobs | Planned; deployment/operation start and completion | Deployment accountability; data-integrity and deletion verification | Operator/system; deployment/job/catalogue version | Deployment/version ID, operator role, timestamps, outcome, correlation ID, aggregate counts. No row contents or user-ID lists | Designed to be non-personal and useful for an annual operations review | DEC-013; DEP-06, DEP-08 |
| User-visible activity projection | Planned; derived from approved account/security/export/sharing/admin events | User awareness and account security | Account subject; filtered source events | Safe event label, timestamp, outcome, coarse context where approved. No other-user data, internal detection rule, IP, private note, or evidence | Has no purpose after account purge | DEC-013; FND-05, DEP-08, DEP-09 |

## 5. Approved retention schedule

Unless a row says otherwise, the clock starts at the event timestamp, expiry is
calendar-based, and final account purge removes the account's user-visible copy
even if a minimized internal event has time remaining. A hold is permitted only
for an active documented security incident, actual or sufficiently contemplated
proceedings, a valid regulatory/legal preservation requirement, or a defined
dispute needing specific evidence. Every hold is reviewed at least every 90
calendar days.

| Category | Active-account treatment | Recovery and final-purge treatment | Actor treatment | Normal retention and clock | Access and storage protection | Deletion and backup treatment | Evidence, confidence, and review status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Authentication and account security | Retain minimum allowlisted event and resolvable audit-subject mapping | Retain during optional recovery. At purge destroy the mapping and keep only an irreversible anonymous correlation reference unless held | Identifiable/pseudonymous while mapping exists; anonymous after mapping destruction | 6 calendar months from event | Security role only; append-only/tamper-evident, encrypted, monitored reads/exports | Hard-delete on expiry; backup copy beyond use until DEC-012 expiry | NCSC security recommendation supports at least six months for selected incident questions. Medium confidence; technically security-reviewed; owner legal-risk reviewed |
| User-visible activity | Filtered view available to account owner | Available while recoverable; delete at final purge | Account-linked only | Up to 6 calendar months from source event | Generated projection; no direct audit-store access | Delete projection/source linkage at purge; backup beyond use | Product proposal aligned to security source. Medium confidence; product-owner approved |
| Privileged-access lifecycle | Retain minimized event and operator identity mapping | Former ordinary-account fields purge normally; dedicated operator mapping remains only for its accountability period unless held | Identifiable operator for 12 months, then role-only anonymous reference | 12 calendar months from event | Owner/security role only; strong integrity, reauthentication, access monitoring, controlled export | Destroy identity mapping and expired event; backup beyond use | Project security recommendation supporting an annual accountability review. Medium confidence; security-reviewed; owner legal-risk reviewed |
| Moderation report, outcome, appeal, and complaint | Party identities available for handling and one reconsideration | Delete with account if purge occurs earlier unless an active safety/legal hold applies; do not preserve deleted-user identity merely for provenance | Reporter/author identifiable for challenge window; moderator follows privileged mapping; decision later anonymous | Identifiable case: 30 calendar days after final decision. Anonymous decision: 12 calendar months after final decision | Scoped moderator access; affected parties see safe outcome only; no security-data access | Remove party mappings at 30 days; delete anonymous decision at 12 months; backup beyond use | Project proposal for challenge handling plus annual safety review. Medium confidence; owner approved |
| Catalogue/public-content provenance | Retain resource/version and minimum contributor/moderator provenance | Former contributor is irreversibly anonymized at final purge unless held. Qualifying public content follows DEC-014 where applicable | Contributor anonymous after purge; moderator identifiable for 12 months, then role-only | Life of affected active version plus 12 calendar months after supersession/withdrawal | Scoped catalogue/moderation access; immutable domain versions and controlled corrections | Delete expired personal mappings and later provenance record; backup beyond use | Product provenance period, not law. Medium confidence; owner approved |
| Private recipe/plan/version/override/snapshot content | Domain data retained for user functionality and restoration | Retain during optional 30-day recovery, then delete; immediate purge when recovery is waived | Delete owner mapping and private content | Account life plus up to 30 calendar days from deletion request | Normal owner authorization; no duplication into security audit | Hard-delete at final purge; backup beyond use | Confirmed product classification, not audit retention. High confidence; owner approved |
| Deletion/recovery/anonymization workflow | Identifiable through request and recovery | Optional 30-day disabled recovery. Authenticated user may waive it. At purge replace with random non-derived receipt | Delete subject mapping at purge | Identifiable until purge; anonymous receipt for 12 calendar months from successful purge | Privacy owner and required security access; idempotent/reconciled purge jobs | Delete receipt at 12 months; restoration must replay purge | Project verification period. Medium confidence; owner approved |
| Confirmed under-13 account and public content | Disable account on confirmation | No recovery: purge private data immediately; hide and delete the child's public recipes/plans. Independent other-user copies retain their own lifecycle | Delete child identity except scoped required safety/legal evidence | Immediate purge, subject only to separate statutory evidence/hold periods | Privacy/security roles for the purge; content hidden immediately | Verified hard deletion; backup beyond use | Child-protection product policy. Medium confidence; owner legal-risk reviewed |
| Export archive and export event | Archive accessible only to requesting user through expiring credential | Purge archive/event sooner at final purge unless held | Event subject follows security mapping | Archive and credential: 7 calendar days from creation. Content-free event: 6 months from event | Owner-only archive; security-role event; archive encrypted and unlogged | Delete archive/credential at 7 days; provider/backup expiry must honor policy | Project exposure-limitation period. Medium confidence; owner approved |
| Online-safety assessments and measures | Keep current records durable, clear, accessible, and up to date | Not account-owned; owner/operator name remains as controller/responsible person | Responsible and approving operator remains identifiable in the governance record | Current version while current; each superseded version for 3 calendar years from replacement | Owner/safety role; protected governance repository; capable of controlled Ofcom production | Delete superseded record after three years unless a specific notice/hold applies | Ofcom regulator guidance, not a generic user-event period. High confidence in cited baseline; scope is owner-assessed, not professionally confirmed |
| CSEA associated evidence | Store only when detected content is reportable and applicable rules require it | Statutory evidence overrides ordinary purge only for required fields and period | Remains identifiable as required; raw IP allowed only here when available and required | 1 calendar year from reporting | Separate highly protected evidence store; named safety/security access only; monitored access/export | Securely delete after one year unless a valid extended preservation notice applies; controlled backup expiry | Legal requirement where the 2026 reporting regulations apply. High confidence in period; applicability owner-assessed |
| CSEA report reference | Retain minimal proof of report | Survives account purge without unrelated user fields | No user identity unless the required reference itself entails it | 5 calendar years from reporting | Separate protected reporting register | Delete at five years unless a valid hold applies | Legal requirement where applicable. High confidence in period; applicability owner-assessed |
| Valid deceased-child information notice | Not created without a valid notice | Overrides purge only for specified information | As required by notice | Exact period stated in the notice | Separate legal hold; named owner/security access | Release/delete when notice permits; backup handling follows notice and DEC-012 | Specific legal obligation when served; application requires case-by-case verification |
| Non-breach security incident | Retain minimized case summary and evidence references | Minimize/anonymize subject fields at closure where possible | Identifiable only while necessary for investigation/hold | Summary: 12 calendar months after closure. Underlying events keep their original six-month clocks | Security role; protected case store | Delete summary at expiry; do not reset source clocks | Project security period supporting one lessons-learned review. Medium confidence; security-reviewed |
| Personal-data breach | Retain minimum facts, effects, notification decision, remediation, and necessary evidence | Minimize/anonymize subject data at closure where possible | Identifiable only where still necessary | Summary: 3 calendar years after closure. Identifiable evidence: 12 calendar months after closure or normal six-month event expiry, whichever is later | Security/privacy owner and case-specific adviser access | Delete evidence/summary on their separate clocks unless held | Article 33(5) requires breach documentation but sets no UK-wide period; periods are owner-approved project proposals. Medium confidence; owner legal-risk reviewed |
| Ordinary application/error logs | Retain redacted troubleshooting fields only | Delete on normal clock and purge account-linked residuals sooner where feasible | Avoid identity; use correlation ID | 14 calendar days from creation | Operations access; separated from security audit; encrypted and rotated | Provider/local rotation must enforce deletion; backup beyond use | Project operational period. Medium confidence; security-reviewed |
| Failed-job records | Retain minimized metadata while retry/diagnosis remains possible | Remove account-linked jobs/payloads at purge unless safe terminal handling requires immediate completion | User reference only while workflow needs it | Metadata: 7 calendar days after final failure or resolution. Personal payload: remove immediately once retry is no longer possible | Operations only; encrypted and redacted | Hard-delete payload/metadata on their clocks; prevent replay after purge | Project operational period. Medium confidence; security-reviewed |
| Import/OCR source and metadata | Retain source only while processing/retry remains authorized | Purge sooner with account. Audit contains no source/extracted content | User-linked only during processing | Successful/terminal source: delete within 24 hours. Abandoned upload: 7 calendar days. Metadata: 30 calendar days from terminal outcome | Owner/processor for source; scoped operations for minimized failure data | Verified object deletion and provider propagation; backup beyond use | Project operational periods. Medium confidence; owner approved |
| Migration/backfill/rebuild/purge evidence | Retain non-personal operation record | Not account-owned; row-level personal exception is removed after resolution or moved into incident case | Operator role; no user lists | 12 calendar months from completion | Operations/security roles; immutable release evidence | Delete at expiry; personal exception follows its incident category | Project operational-accountability period. Medium confidence; security-reviewed |

## 6. Field-level minimization rules

Ordinary audit events may contain only:

- an allowlisted event type and narrowly defined purpose;
- an immutable event identifier;
- a reliable UTC timestamp;
- a structured outcome and reason code;
- a resource or immutable version reference;
- an anonymous session/request/correlation reference;
- a purpose-specific actor and subject reference; and
- changed field names and narrowly justified structured values.

Ordinary audit events must reject:

- passwords, password hashes, second-factor secrets, API credentials, session
  cookies, reset tokens, verification/reset links, and other credentials;
- raw IP addresses and full user agents;
- complete model snapshots or arbitrary before-and-after payloads;
- private recipe, plan, diary, target, OCR, import, or export content;
- health-revealing content, allegations, or criminal-offence material;
- raw free-text administrator reasons where a structured code is sufficient;
- mail bodies, request bodies, query values, SQL values, and unnecessary stack
  context; and
- source files, extracted text, or paths containing user identity.

Raw IP information is permitted only inside a separately protected statutory
evidence package when it is available and legally required, such as an
applicable CSEA report. It remains prohibited in ordinary FND-05 records.

Free text is exceptional, length-limited, access-restricted, and subject to
redaction. Sensitive or health-revealing content that is not strictly necessary
for a separately protected incident purpose is deleted rather than retained.

Pseudonymization remains personal-data processing. A record is called
anonymous only after the relevant mapping, derivation key, and other reasonable
route to re-identification have been removed.

## 7. Account-erasure behavior

### Active account

Domain content and approved audit events follow their separate purposes and
clocks. Users see only a filtered activity projection, never the audit store.

### Optional 30-day recovery

When ordinary deletion is requested, the account and private content become
disabled and non-public but remain recoverable for up to 30 calendar days.
Security and deletion-workflow records remain protected. An authenticated user
may waive recovery and request immediate final purge, subject to the
sole-administrator safeguard and a documented hold.

### Final purge

Final purge:

- deletes the account identity, profile, sessions, reset state, private content,
  user-visible activity history, and unnecessary queued work;
- deletes private recipe versions, nutrition overrides, plans, diary entries,
  targets, and snapshots as user content;
- severs ordinary security-event identity mappings while leaving anonymous
  correlation for the remaining event period;
- irreversibly anonymizes surviving shared-catalogue and qualifying
  public-content provenance;
- preserves only evidence covered by an unexpired purpose-specific schedule or
  scoped hold;
- creates a random non-derived purge receipt; and
- initiates deletion/anonymization at external processors.

A confirmed under-13 account bypasses recovery. It is disabled and purged, and
its public recipes/plans are hidden and deleted. Other users' independent copies
remain separately owned and subject to moderation.

### Backups and restoration

DEC-012 must define the explicit backup lifecycle. Until then, no backup period
is approved by this decision. A purged record in an immutable backup is beyond
operational use, access-restricted, and encrypted until scheduled expiry. A
restored system must replay completed purges before returning to service.

## 8. Holds and exceptions

A hold requires:

- a specific incident, dispute, proceeding, regulatory inquiry, court/tribunal
  order, or valid preservation notice;
- identified evidence categories and records;
- the purpose and authority relied upon;
- an approving owner and, where applicable, the source of legal authority;
- a creation date and next review date;
- access restricted to the case; and
- review no later than every 90 calendar days.

The product owner approves exceptions. A security incident hold is initiated
under the separate security role. A legal/regulatory hold requires validation
of the applicable notice, provision, order, or other authority. Professional
legal review was not adopted as a standing project requirement, but specialist
advice may still be sought for a particular high-risk request.

When a hold ends, records return to the original schedule. The hold does not
restart the normal retention clock. If that clock has already expired, the
released evidence is deleted promptly.

## 9. Access and security controls

- Ordinary users and logged-out visitors are denied internal audit access.
- User-visible activity is a filtered projection containing no internal
  detection logic, IP data, other-user information, or private notes.
- Security access is limited to the owner acting under a distinct security role
  and any later specifically authorized security personnel.
- Moderation access is limited to the owner and specifically authorized,
  duty-scoped moderators; it grants no automatic security-log access.
- Legally protected evidence uses a separate store and named case-specific
  access.
- Application audit writes are append-only. Corrections use linked events, not
  mutation of prior evidence.
- Storage uses encryption in transit and at rest, reliable timestamps,
  integrity/tamper detection, correlation identifiers, and monitored reads and
  exports.
- Sensitive access uses reauthentication and controlled break-glass behavior.
- Evidence exports are case-specific, encrypted, access-limited, and assigned
  their own deletion date.
- Deletion jobs are idempotent, reconciled, monitored, and produce no
  identity-bearing permanent receipt.
- Access monitoring is bounded so it does not create recursive, indefinitely
  retained audit history.

## 10. Children and online-safety assumptions

Public recipes and meal plans can contain user-entered titles, descriptions,
instructions, ingredient text, notes, images, and imported text visible to
other users without prior moderation. The owner-led review conservatively
treats this as potentially regulated user-to-user functionality.

- Registration uses a self-declared age band, not date of birth.
- A declared under-13 user cannot register.
- Users declaring ages 13–17 receive age-appropriate privacy information and
  safeguards.
- A children/privacy/online-safety DPIA is assumed by the owner to have been
  completed externally with acceptable residual risk; no repository artifact
  currently verifies it.
- Terms prohibit content promoting or instructing eating disorders, self-harm,
  suicide, abuse, illegal activity, or another person's private information.
- Initial moderation is report-driven. The residual risk of harmful content
  appearing before a report is accepted by the owner and must be reassessed as
  usage changes.
- High-risk reports immediately hide the affected content pending review.
- Authors and reporters receive an outcome and one accessible reconsideration
  route.
- The owner reviews illegal-content, children's-risk, and safety-measure
  assessments every 12 calendar months and after a significant product change,
  serious incident, or material change in user behavior.

## 11. External processors and notices

Before production use, every hosting, mail, backup, OCR, analytics, or logging
provider that processes personal data must be entered in a processor inventory.
The owner reviews the provider's contract, locations, subprocessors, access,
security, deletion, export, backup, and termination behavior.

The privacy notice identifies the controller by legal name, operating as
VibeDietr, and provides a dedicated site contact email. It explains:

- audit categories and purposes;
- lawful bases adopted by the owner;
- retention periods or criteria;
- the optional recovery period and immediate-purge choice;
- final purge, anonymization, public-content survival, and under-13 handling;
- security/moderation access boundaries;
- exceptional holds and applicable statutory evidence;
- backups and external processors;
- user-visible activity and rights-request handling; and
- children's information in age-appropriate language.

Rights-request searches include relevant security, moderation, and hold records
about the requester. Disclosure is controlled, third-party information is
redacted, and any restriction or exemption is assessed and documented case by
case. Users never receive direct audit-store access.

## 12. Lawful-basis record requiring owner maintenance

This is the owner-led assessment, not a legal conclusion:

| Purpose | Proposed UK GDPR basis | Required record |
| --- | --- | --- |
| Ordinary account and network security | Legitimate interests | Purpose, necessity, and balancing assessment, including children and the no-raw-IP residual risk |
| Ordinary moderation, appeals, and identifiable provenance | Legitimate interests | Purpose, necessity, balancing, safeguards, challenge process, and participant expectations |
| Optional deletion recovery | Legitimate interests | Accidental/malicious deletion purpose, optional nature, waiver, 30-day maximum, and automatic purge |
| Specific Online Safety Act/CSEA/Ofcom-notice processing | Legal obligation where the provision applies | Exact provision/notice, necessity, fields, period, access, and deletion date |
| Personal-data breach documentation | Legal obligation where Article 33(5) applies | Facts, effects, remediation, notification analysis, and approved schedule |

Consent is not used for necessary security or moderation controls. Handling
special-category or criminal-offence data requires both an Article 6 basis and
the additional applicable condition. Ordinary audit deliberately excludes such
content.

## 13. Evidence register

All sources below were accessed on 31 July 2026.

| Source | Classification | Use in this schedule |
| --- | --- | --- |
| [ICO storage limitation](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/storage-limitation/) | Regulator guidance explaining a legal principle | No universal UK GDPR period; justify purpose, identifiability, review, deletion, anonymization, and backup treatment |
| [ICO data minimization](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/data-minimisation/) | Regulator guidance explaining a legal principle | Keep only adequate, relevant, and necessary fields; prohibit speculative collection |
| [ICO right to erasure](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/individual-rights/right-to-erasure/) | Regulator guidance explaining legal requirements | Case-specific exceptions; beyond-use backup handling; recovery may be waived |
| [ICO legitimate interests for network security](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/legitimate-interests/when-can-we-rely-on-legitimate-interests/) | Regulator lawful-basis guidance | Security can be a legitimate interest but requires purpose, necessity, and balancing tests |
| [ICO personal-data breach guide](https://ico.org.uk/for-organisations/report-a-breach/personal-data-breach/personal-data-breaches-a-guide/) | Regulator guidance explaining Article 33(5) | Breach documentation is required; no universal retention period is supplied |
| [ICO breach-management audit framework](https://ico.org.uk/for-organisations/advice-and-services/audits/data-protection-audit-framework/toolkits/personal-data-breach-management/breach-identification-assessment-and-logging/) | Regulator accountability guidance | Restrict access, minimize/anonymize, define a basis/period, and prove deletion |
| [ICO right to be informed](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/individual-rights/right-to-be-informed/) | Regulator guidance explaining transparency requirements | Identify controller; state purposes, bases, recipients, and periods/criteria |
| [ICO controller/processor contracts](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/accountability-and-governance/contracts-and-liabilities-between-controllers-and-processors-multi/what-needs-to-be-included-in-the-contract/) | Regulator guidance explaining Article 28 | Processor security, subprocessing, deletion/return, audits, and backup treatment |
| [ICO children's code introduction](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/childrens-information/childrens-code-guidance-and-resources/introduction-to-the-childrens-code/) | Statutory regulatory code guidance | Services likely accessed by under-18s require child-specific assessment and safeguards |
| [ICO children and ISS consent](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/childrens-information/children-and-the-uk-gdpr/what-are-the-rules-about-an-iss-and-consent/) | Regulator legal guidance | UK consent threshold is 13 where ISS consent is used; online services to children are likely to require a DPIA |
| [ICO special-category data](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/lawful-basis/special-category-data/what-is-special-category-data/) | Regulator legal guidance | Diet/plan/report content can reveal health data depending on context |
| [NCSC introduction to security logging](https://www.ncsc.gov.uk/guidance/introduction-logging-security-purposes) | Security recommendation, not law | Retain logs selected to answer incident questions for at least six months; protect logs; review strategy every 6–12 months |
| [NCSC CAF security monitoring](https://www.ncsc.gov.uk/collection/cyber-assessment-framework/caf-objective-c-detecting-cyber-security-events/principle-c1-security-monitoring) | Security recommendation, not law | Define purposes, protect integrity/access, monitor use, and delete after the selected period |
| [Ofcom protection-of-children duties](https://www.ofcom.org.uk/online-safety/protecting-children/protection-of-children-duties-under-the-online-safety-act) | Official regulator guidance | Children access/risk assessments, safety measures, reporting, complaints, and records for in-scope services |
| [Ofcom record-keeping and review guidance](https://www.ofcom.org.uk/siteassets/resources/documents/online-safety/information-for-industry/illegal-harms/updates/record-keeping-and-review-guidance.pdf?v=420034) | Official regulator guidance for enforceable duties | Current records plus superseded versions for the provider's longer policy or at least three calendar/financial years |
| [Ofcom illegal-content duties](https://www.ofcom.org.uk/online-safety/illegal-and-harmful-content/illegal-content-duties-under-the-online-safety-act) | Official regulator guidance | Easy reporting, complaints procedure, proportionate controls, and swift removal for in-scope U2U services |
| [Ofcom CSEA reporting guidance](https://www.ofcom.org.uk/online-safety/illegal-and-harmful-content/duty-to-report-child-sexual-exploitation-and-abuse-csea-content-know-the-rules-and-how-to-comply) | Official regulator guidance | Current reporting duty and scope indicators for UK user-to-user providers |
| [Online Safety (CSEA Content Reporting) Regulations 2026](https://www.legislation.gov.uk/uksi/2026/268/contents/made) and [Parliamentary scrutiny](https://publications.parliament.uk/pa/ld5901/ldselect/ldsecleg/282/28205.htm) | Legislation and official parliamentary material | One-year associated evidence and five-year report-reference periods where applicable |
| [Data (Use and Access) Act 2025 explanatory notes](https://www.legislation.gov.uk/ukpga/2025/18/notes/division/4/index.htm) | Official legislation explanatory material | Scoped Ofcom retention notices concerning a deceased child's use of a specified service |
| [Civil Procedure Rules PD31B](https://www.justice.gov.uk/courts/procedure-rules/civil/rules/part31/pd_part31b) | Procedural legal requirement where applicable | Preserve relevant electronic evidence when proceedings are sufficiently contemplated; apply a scoped hold |
| [Limitation Act 1980](https://www.legislation.gov.uk/ukpga/1980/58/pdfs/ukpga_19800058_en.pdf) | Legislation | The six-year simple-contract limitation period is explicitly rejected as a blanket audit-retention justification |

## 14. Review ownership and gates

The product owner owns the schedule and exception approvals. The security role
owns the technical event inventory, minimum useful fields, integrity, access,
incident handling, and deletion verification. The owner-led privacy/legal-risk
record owns the stated bases, notices, erasure exceptions, statutory evidence,
and processor handling.

The decision status remains **product decision made — security reviewed — owner
legal-risk review completed; professional legal review not performed**. It is
not fully or legally approved and must never be represented that way.

| Gate | Required scope | Status at 31 July 2026 |
| --- | --- | --- |
| Product-owner approval | Product-visible history, user notices, anonymization, moderation provenance, and account-erasure experience | Approved by the product owner |
| Security review | Security-event inventory, minimum useful fields, incident-detection retention, log integrity, role separation, access, and scoped holds | Technical policy reviewed; implementation evidence remains required in FND-05 and DEP-09 |
| Legal/privacy review | Lawful bases, erasure exceptions, claim/notice preservation, jurisdiction, privacy wording, former-user identity, and provider terms | Owner-led legal-risk review completed; no qualified professional review performed; reopen on the material-change triggers in section 1 |

The complete policy is reviewed every 12 calendar months and after a serious
incident, material product/architecture change, new jurisdictional targeting,
or relevant change in official guidance or law.

FND-05 is unblocked by this decision. DEP-08 is no longer blocked by DEC-013 but
still depends on DEC-012 and its other roadmap prerequisites. DEP-09 must verify
that actual notices, processors, moderation behavior, and implemented deletion
match this schedule before launch. DEP-06 remains constrained by DEC-012's
unresolved backup lifecycle.
