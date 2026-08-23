# Authorization and privacy matrix

## 1. Purpose and authority

This document is the product-rule authority for authorization, privacy,
sharing, publication, ownership, and deletion behavior for VibeDietr's current
and planned resources. It completes roadmap item FND-03. It describes required
outcomes; it does not prescribe Laravel policy,
gate, middleware, route, controller, Livewire, database, or storage design.

The sources of truth used here are [`PRODUCT_SPEC.md`](PRODUCT_SPEC.md),
[`CURRENT_STATE.md`](CURRENT_STATE.md), [`DOMAIN_MODEL.md`](DOMAIN_MODEL.md),
[`DECISIONS.md`](DECISIONS.md), [`ROADMAP.md`](ROADMAP.md), and
[`DOMAIN_MIGRATION_PLAN.md`](DOMAIN_MIGRATION_PLAN.md). Where the current
application and intended product differ, the matrix identifies both. A
planned rule does not describe behavior that is already implemented.

Matrix wording has these meanings:

- **Confirmed** is an explicit requirement in `PRODUCT_SPEC.md` or a recorded
  decision.
- **Current** is behavior verified in the repository, even when it conflicts
  with the planned product.
- **Proposed** is a safe default that requires product-owner confirmation. It
  must not be treated as a settled product requirement.
- **Not yet specified** means the product documents do not determine the
  behavior and this task does not choose it.

## 2. Resolved decision dependencies

### DEC-014 — Public meal plans after owner deletion

- **Title:** Public meal plans after owner deletion.
- **Current status:** Decided.
- **Confirmed outcome:** Public-plan handling is automatic from the deletion
  request. A plan bookmarked by another user is anonymized as
  `Former VibeDietr user`, unlisted, retained at its existing URL, closed to
  new bookmarks, and deleted when its final bookmark is removed. A public plan
  with no other-user bookmark becomes unavailable immediately. Recovery within
  30 days restores ownership, attribution, unavailable plans, and prior
  visibility; final purge removes recovery-only links and prevents later
  reclamation.
- **Effect on FND-03:** DEC-014 no longer blocks FND-03. The public-plan,
  nested-snapshot, and anonymized-owner rows below now state the approved
  deletion, retention, recovery, and public-safety rules.
- **Remaining boundaries:** Backup expiry remains DEC-012 and security/legal
  audit handling follows the approved DEC-013 schedule. Broader inactive-data
  retention remains a future policy question; DEC-013 does not authorize
  blanket retention of inactive product data.

### DEC-009 — Initial administrator assignment

- **Current status:** Decided.
- **Confirmed outcome:** Initial production assignment is a one-time,
  deployment-configured CLI operation by one authenticated and traceable trusted
  operator. It targets an existing active account with verified email and
  a confirmed TOTP factor and acknowledged recovery codes, succeeds only with zero
  administrators and an unset persistent completion marker, and fails closed on
  verification, concurrency, audit, or configuration failure. Bootstrap never
  reopens.
- **Lifecycle outcome:** Multiple administrators are supported. Routine
  promotion requires an existing administrator's recent re-authentication and a
  fresh TOTP, a verified, TOTP-enrolled target, and the target's
  TOTP-confirmed acceptance within 24 hours. Another administrator may
  revoke with the same acting-admin controls only when at least one other active
  administrator will remain. Self-revocation and sole-administrator deletion or
  revocation are denied. Separate configured CLI break-glass recovery may
  activate a replacement and revoke a compromised administrator without reusing
  bootstrap.
- **Evidence and environment:** Privilege events are audited, security-notified,
  and secret-free. Production requires linked operational evidence, approved
  second-factor capability, and reliable notification delivery. Local
  development keeps the core CLI, repeat-prevention, last-admin, and application
  audit safeguards but may use explicit test states and omit external evidence.
- **Remaining boundaries:** Audit access and retention follow the approved
  DEC-013 schedule. DEC-015 now defines locally verified TOTP enrollment,
  verification, and recovery; DEC-016 defines reliable
  security-notification delivery. DEC-009 does not grant administrators access
  to private user content beyond separately confirmed resource permissions.

### DEC-015 — Administrator second-factor mechanism and recovery

- **Current status:** Decided.
- **Confirmed outcome:** Administrator accounts use locally verified RFC 6238
  TOTP with no required external provider, recurring verification charge,
  specific authenticator application, or smartphone. Any active,
  email-verified user may enroll their own factor without gaining privilege.
  Initial scope is one confirmed TOTP factor and ten mandatory, acknowledged,
  single-use recovery codes; multiple factors and WebAuthn/passkeys are future
  enhancements.
- **Verification and recovery:** TOTP uses a unique encrypted seed, six digits,
  a 30-second timestep, bounded clock skew, atomic replay prevention, and
  account/factor/operation plus IP throttling. Recovery-code hashes are one-way
  and plaintext is shown only at creation or regeneration. Lost-factor recovery
  uses one recovery code, a different active administrator's password and fresh
  TOTP, or a short-lived single-use CLI authorization backed by traceable host
  access for total sole-administrator loss. Every path requires the affected
  user to confirm a replacement factor and invalidates old factors, codes, and
  sessions.
- **Access, evidence, and environment:** Administrators cannot remove their
  final factor while retaining administrator status. Passwords, reset emails,
  profile edits, ordinary sessions, endpoints, direct database changes, support
  contact, and environment flags never bypass TOTP. Enrollment and recovery
  provide accessible manual and QR paths, screen-reader support, paste/autofill,
  and accessible recovery-code handling. Events are correlated, audited,
  security-notified, and secret-free. Production fails closed without secure
  key, clock, replay, throttle, audit, session, and notification capabilities;
  explicit deterministic test adapters cannot run in production.
- **Remaining boundaries:** FND-13 implements the approved mechanism and
  recovery behavior. DEC-016 selects notification delivery. Any future
  multiple-factor, passkey, hardware-key, or provider-managed design requires a
  separately reviewed change.

## 3. Current repository behavior and conflicts

The current application implements accounts, private profiles, one user-owned
`Ingredient` resource, and the FND-04 administrator authorization foundation.
It has the FND-14 production administrator lifecycle but no recipe,
shared-catalogue, moderation, planning, diary, target, import, or export
authorization. FND-05 implements the audit authorization foundation described below.

- `users.is_administrator` is non-null, defaults to false, and is excluded from
  ordinary mass assignment, registration, and profile state.
- The central `access-admin` gate allows confirmed administrators and denies
  ordinary users and guests.
- Administrator-only routes combine `auth` with `can:access-admin` so guests
  redirect to login and authenticated ordinary users receive 403.
- Controller and Livewire actions callable independently must invoke
  `authorize('access-admin')` at the action boundary.
- Production assignment and revocation exist only through the FND-14 lifecycle services.

- `AuditEventPolicy` denies all generic browsing and all create/update/delete
  abilities. Creation occurs only through the trusted internal recorder.
- Guests and ordinary users cannot view an internal event. Administrators may
  view an individual moderation or catalogue-provenance event; administrator
  status alone does not grant account-security or privileged-lifecycle access.
- No production audit route, browser, filtered activity view, export, security
  role, or moderator role is implemented. Those later views remain subject to
  the purpose-specific matrix and DEC-013 controls.

- Ingredient routes are inside `auth` middleware. Logged-out users cannot use
  the ingredient routes.
- Ingredient list queries filter by the authenticated user's `user_id`.
- `IngredientPolicy` allows any authenticated user to list and create, but
  only the row owner to view, update, or delete a particular ingredient.
  Restore and force-delete are denied.
- Controller create, update, and delete operations invoke the policy at the
  mutation boundary. The Livewire index modal authorizes view/update and the
  show component authorizes view.
- `App\Livewire\Ingredients\Form::save()` invokes the policy at its mutation
  boundary, re-resolves its untrusted scalar ingredient identifier, and denies
  non-owner, forged-identifier, stale-ownership, and guest mutations with 403.
- Ingredient creation associates the authenticated user through trusted
  server-side logic. Ownership is excluded from model mass assignment and
  update allowlists, so submitted ownership cannot create for another user or
  reassign an existing record.
- Account deletion currently checks the user's password, immediately deletes
  the user, logs them out, and offers no recovery period. The database then
  permanently deletes that user's ingredients through `ON DELETE CASCADE`.
- The target product instead offers account recovery for up to 30 days unless
  the user waives it or a confirmed under-13 account requires immediate purge,
  plus selective purge/anonymization and a shared catalogue whose submitter is
  provenance rather than owner. Approved catalogue records survive deletion.

REC-05 now implements the owner-only finalization mutation. Draft lifecycle
remains private and plan-ineligible regardless of its intended visibility.
Finalization requires the authoritative persisted aggregate to contain a title,
positive servings, an ingredient line, and a nonblank instruction step. It
atomically creates immutable version 1, changes lifecycle to `finalized`, keeps
visibility separate, and records a minimized audit event. Public is the
server-side default; an explicitly selected private visibility is preserved.
A finalized private recipe is plan-eligible only for its owner under the
currently represented rule, while a finalized public recipe is generally
eligible.

REC-06 now exposes the conventional recipe show route to guests. A centralized
query admits the owner or a finalized current-version recipe whose current
visibility is public; guessed draft/private identifiers return 404 without
content. Finalized pages use an explicit allowlisted projection of the current
immutable version and never serialize the owner or raw relationship graph.
Only the creator may change finalized visibility. Public-to-private and
private-to-public transitions preserve lifecycle, every finalized version, and
recipe children while recording a minimized product-history audit event.
REC-07 now routes creator-owned finalized-content editing through one private
draft revision. Guests and non-owners cannot create, open, publish, or abandon
it. Readers continue to receive only the current immutable version; publication
and abandonment preserve durable visibility.

REC-09 now provides guest and authenticated title browse/search through the
same centralized public-readable scope. Discovery projects only durable recipe
ID, current-version title and servings, and current finalization time. It does
not load or serialize owners, account/security fields, mutable draft state,
active-revision or historical-version identifiers, or private recipes. Public
tag discovery remains deferred because REC-13 has not implemented the distinct
public-tag records and assignments.

These current behaviors remain facts until later implementation work changes
them. This document does not change them.

## 4. Authorization principles

### Confirmed product requirements

- Ownership, visibility, and edit permission are separate.
- A finalized recipe defaults to public, although its creator may make it
  private. Drafts are always private.
- Public recipes and public plans are read-only to non-owners and visible to
  logged-out visitors.
- A meal plan is private by default. Only its owner may edit it.
- Selected plan shares are read-only. An authenticated viewer may make an
  independent plan copy that they own.
- A plan cannot be public while it would expose a private recipe.
- A selected-user plan share may expose only the private recipe snapshots
  needed to understand the plan, and only after explicit owner acknowledgement.
- Ordinary users propose shared-catalogue changes; they do not directly edit
  or delete barcode-imported shared catalogue records.
- Draft imports, personal organisation, diary data, targets, and one-off items
  begin private. Uploads used for extraction are transient and are deleted
  after processing.
- Historical plan and diary snapshots remain stable when recipe or catalogue
  data changes.
- At final purge after an optional recovery period of up to 30 days, specified
  private data is removed. An authenticated user may waive recovery and a
  confirmed under-13 account bypasses it, subject to the sole-administrator
  safeguard and scoped holds. Public recipes are anonymized, approved catalogue
  contributions retain no identifying submitter, and independently owned
  remixes and plan copies are unaffected.

### Proposed defaults requiring confirmation

The following principles apply in this document only where no confirmed rule
overrides them. Each remains proposed and must be confirmed before its affected
policy is implemented:

- Resources are private by default.
- Access is denied unless explicitly granted.
- Sharing one resource does not grant access to unrelated account data.
- Public access is read-only.
- Administrators do not automatically view, edit, or delete user-owned private
  content merely because they are administrators.
- Sensitive diary, target, and progress information is never public.
- Authorization is enforced server-side at every read and mutation boundary,
  not only by hiding interface controls.
- Deletion preserves referential integrity and does not disclose content that
  was private before deletion.

## 5. Matrix

`Owner deleted` below normally means final purge after an optional recovery
period of up to 30 days. An authenticated user may waive recovery and a
confirmed under-13 account is purged without recovery, subject to the
sole-administrator safeguard and scoped holds. DEC-014 is the explicit
public-plan exception: its
anonymization, unlisting, bookmark, and availability rules begin when deletion
is requested, while protected reattribution remains possible until the
deadline. Backup expiry remains subject to DEC-012. Audit access, retention,
anonymization, and narrow legal/security exceptions follow the approved
`AUDIT_RETENTION_SCHEDULE.md` under DEC-013.

| Resource | Current or planned | Owner | Creator | Default visibility | Logged-out viewer | Authenticated non-owner viewer | Owner permissions | Shared-user permissions | Administrator permissions | Create rule | View rule | Edit rule | Delete rule | Share or publish rule | Ownership-transfer rule | Behavior when the owner is deleted | Audit requirement | Relevant decision IDs | Relevant roadmap IDs | Notes or unresolved questions |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| User accounts and private profiles | Current; planned deletion behavior differs | Account user | Registrant | Owner only | Denied, except authentication and recovery screens | Denied | Allowed: view and edit own profile, request export, and request deletion only when not the sole active administrator; current deletion requires password | Not applicable | Proposed: denied private-profile access unless a separately authorized support/security purpose is confirmed | Public registration currently allowed; self-service account creation only | Owner only | Owner only | Current: immediate hard delete after password check. Planned: optional recovery for up to 30 days, then purge/anonymize by resource; the sole active administrator is denied deletion until a replacement is active | Denied | Not yet specified; no transfer requirement exists | Account row/private profile removed after recovery; public attribution and contributions follow their own rows | Planned deletion, recovery, export, anonymization, and administrator-lifecycle events are auditable | DEC-008, DEC-009, DEC-012, DEC-013, DEC-014 | FND-03, FND-14, REC-14, DEP-07, DEP-08, DEP-09 | Email is always private. DEC-009 administrator lifecycle does not authorize private-profile access. |
| Public attribution profiles | Planned | Account user | Account user | Disabled unless the user enables the profile | Allowed when enabled | Allowed when enabled | Allowed: configure attribution and enable/disable listing | Public read-only only; no separate shared-user grant | Proposed: same read access as any viewer; no edit unless acting as owner | Owner only | Public when enabled; email, private recipes, and personal organisation denied | Owner only | Follows account deletion/anonymization flow | Publishing profile is owner only; disabling it does not make public recipes private | Denied; attribution choice is not account transfer | Personal profile data is removed; retained public recipes use anonymized former-user attribution | Attribution changes and anonymization are auditable where needed | DEC-012, DEC-013 | REC-14, DEP-08 | Confirmed that disabling a profile does not unpublish recipes. Exact retained public attribution fields are not yet specified. |
| Recipe drafts | Current identity/create/view/edit; later workflows planned | Recipe creator | Recipe creator | Owner only | Denied | Denied | Allowed: create, view, edit, delete, import into, and finalize when valid | Denied | Proposed: denied view/edit/delete unless acting as owner | Authenticated user creates own draft; imported recipes always begin here | Owner only | Owner only | Owner only; account purge permanently removes it | Cannot be shared, published for public reading, bookmarked, or placed in plans; finalization creates a published version | Not yet specified; no transfer workflow is planned | Permanently removed at final purge after any recovery period | Version creation/import provenance and publication are auditable | DEC-005, DEC-006, DEC-007, DEC-012, DEC-013 | REC-01 through REC-05, REC-15 through REC-17, DEP-08 | Draft privacy overrides any intended finalized visibility. |
| Published or finalized recipes | Current finalization, public/private reads, and visibility changes; later workflows planned | Recipe creator | Recipe creator | Public by default; owner may choose private | Allowed only when public | Allowed when public; private denied except scoped plan-snapshot access | Allowed: view, create draft revision, publish revision, change visibility, delete, and create own remix | Plan-share users: read-only access only to the private snapshot needed by that share; no live-recipe edit, copy-as-recipe, or reshare grant | Proposed: public view only; private view/edit/delete denied unless acting as owner | Created only by valid draft finalization | Owner; public viewers when public; selected plan viewers only through scoped snapshot | Owner alone, through a draft revision | Owner only; deletion must preserve remixes, unavailable bookmarks, and plan snapshots | Owner only may make public/private. Public access is read-only. There is no standalone selected-user recipe share | Not yet specified; remixes are copies, not transfers | Private recipes are permanently removed; public recipes remain with anonymized former-user attribution | Publication, visibility changes, deletion, nutrition overrides, and lineage are auditable | DEC-012, DEC-013 | REC-05 through REC-11, REC-14, NUT-17, DEP-08 | Public recipe access never includes private account data. “Unpublished” means public access is removed, not necessarily row deletion. |
| Recipe versions and draft revisions | Current immutable versions, current-version reads, and one active private draft revision | Recipe creator | Recipe creator | Draft revision: owner only. Current published version: inherits recipe visibility | Allowed only for the current public version exposed by its recipe | Same as recipe; scoped plan access is to the pinned snapshot/version only | Allowed: view own history, edit draft revision, publish replacement; published versions are immutable inputs | Read-only only where the parent recipe or a plan snapshot grants it | Proposed: no private access or mutation; public read follows recipe | Draft revision is created by editing a finalized recipe; published version by explicit publication | Parent recipe visibility plus snapshot scope | Only draft revision editable by owner; published version not editable | Not yet specified for superseded versions; versions referenced by snapshots or lineage cannot be silently removed | Only owner publishes a draft revision; versions are not shared independently | Denied; version history follows recipe ownership | Current public recipe remains anonymized; private/draft data is removed. Retention of unreferenced superseded versions is not yet specified | Required for versions, provenance, overrides, and remix lineage | DEC-012, DEC-013 | REC-05, REC-07, REC-11, PLAN-03, NUT-15, NUT-17, DEP-08 | Plans pin a version; a newer publication never silently changes the plan entry. |
| Recipe ingredient lines, instructions, matches, and recipe nutrition records | Current recipe lines/instructions and finalized read projection; later nested records planned | Recipe owner | Recipe creator or import process acting for owner | Inherit draft/version visibility | Allowed only as content of an accessible public recipe/version | Allowed only as content of an accessible recipe or scoped plan snapshot | Allowed as part of own draft; published copies are changed through revision workflow | Read-only snapshot fields needed for a granted plan view; no mutation | Proposed: no private access or direct mutation | Created only inside an owned recipe draft/import | Inherit accessible parent or pinned-snapshot scope | Owner only in draft; no direct edit of immutable published version | Deleted with removable parent only when snapshots, lineage, and audit integrity remain intact | Cannot be independently shared or published | Not applicable independently | Follows parent recipe/version; retained snapshots disclose only the fields their plan access permits | Original text, matches/provenance, overrides, and version inputs require history/audit as specified | DEC-001, DEC-002, DEC-003, DEC-004, DEC-013 | REC-02, REC-03, REC-07, NUT-07, NUT-12, NUT-15 through NUT-18, PLAN-03 | Original ingredient and instruction wording is preserved. Nested identifiers must not bypass parent authorization. |
| Recipe remixes | Planned | Remixer | Remixer; source creator retained as attribution | Private draft initially; finalized visibility follows recipe rule | Allowed only after remix is finalized public | Same as any recipe | Remixer has normal owner permissions | Same as recipe/plan-snapshot rules; source creator receives no edit grant | Proposed: same as other recipes | Authenticated viewer may remix an accessible finalized recipe | Follows remix's own lifecycle and visibility | Remixer alone | Remixer alone | Follows normal recipe publication; source link is attribution, not shared authority | Not yet specified; remix creation is independent copying, not transfer | Follows remix's own visibility; source deletion or source-owner deletion does not remove it | Remix lineage and source version are auditable | DEC-012, DEC-013 | REC-11, REC-14, DEP-08 | Remix remains usable if source is unavailable. Private-source remix is allowed only where the user already has access; exact plan-snapshot-to-remix permission is not specified and is therefore denied by the proposed default. |
| Bookmarks or saved recipes | Planned | Bookmarking user | Bookmarking user | Owner only | Denied | Denied | Allowed: create, view, remove, and privately organise | Denied | Proposed: denied unless acting as owner | Authenticated user may bookmark a public recipe; idempotent | Owner only; recipe content is separately authorized | No content edit; owner may change bookmark organisation | Owner only | Cannot share a bookmark; it does not copy or publish the recipe | Denied | Permanently removed with bookmark owner's private data | Not required for ordinary add/remove unless security/product review requires it | DEC-012, DEC-013 | REC-10, REC-12, DEP-07, DEP-08 | If source is deleted or made private, bookmark remains as an unavailable tombstone and exposes no source content. |
| Recipe collections, folders, and private tags | Planned | Account user | Account user | Owner only | Denied | Denied | Allowed: create, view, edit, delete, and organise owned recipes/bookmarks | Denied | Proposed: denied unless acting as owner | Authenticated user for own account | Owner only | Owner only | Owner only; references are cleaned without deleting recipes/bookmarks | Denied | Not yet specified; no transfer workflow is planned | Permanently removed at final purge after any recovery period | Not required for ordinary organisation changes unless security/product review requires it | DEC-012, DEC-013 | REC-12, DEP-07, DEP-08 | Never included in public recipe or profile responses. |
| Public recipe tags and managed tag vocabularies | Planned | Free-form tag: recipe owner. Managed vocabulary: application | Free-form tag: recipe creator. Managed term: authorized administrator | Public only when attached to an accessible public recipe; management records administrator only | Allowed only as rendered public recipe metadata | Allowed on accessible recipes; managed vocabulary administration denied | Recipe owner may apply/remove allowed tags on own recipe; cannot alter managed definitions | Read-only as recipe metadata | Allowed: create/edit/deprecate managed vocabulary; proposed no direct retagging of user recipes | Owner creates free-form tags; administrator creates managed terms | Inherit recipe visibility; admin management view is administrator only | Owner edits own assignment; administrator edits managed definitions | Owner removes own assignment; administrator deprecates/removes managed term only with referential integrity | Tags do not grant access; public rendering inherits recipe visibility | Not applicable | Recipe tags follow retained/deleted recipe; managed vocabulary survives any contributor account | Managed-term changes and suggestion approval/rejection are auditable where moderation is involved | DEC-009, DEC-013 | REC-13, FND-04, FND-05 | Suggested claims require creator review and cannot be presented as verified with incomplete nutrition. |
| Current user-owned ingredients | Current legacy resource | `ingredients.user_id` user | Same user | Owner only | Denied by authenticated routes | Denied by owner policy/query scope | Current: create, view, edit, and permanently delete | Denied | No administrator role or override exists | Authenticated user; store paths assign authenticated user through trusted server-side association | Owner only | Owner only; controller and Livewire return 403 for non-owner mutation | Owner only; hard delete | Denied | Denied; ownership is not mass assignable or included in mutation allowlists | Current: database cascade permanently deletes all rows immediately with account | No ingredient mutation audit event required by the current audit policy | None | STB-01, STB-03, FND-02 | This row documents legacy behavior, not the target shared catalogue. Restore and force-delete policy actions are denied. |
| Approved shared catalogue records and versions | Planned | No user owner; application shared catalogue | Source import, submitter, or accepted proposal; submitter is provenance only | Shared with authenticated users; logged-out access not yet specified | Proposed: denied until public catalogue browsing is explicitly confirmed | Allowed read-only when approved | Not applicable as user ownership; submitter has no direct edit/delete right | Read-only; recipe/plan use does not grant mutation | Allowed: review staged changes and accept/reject them; direct silent mutation of current version denied by versioning requirement | Successful barcode import or administrator approval creates/advances a record | Approved shared records allowed to authenticated users; proposed guest denial | Ordinary users denied; changes occur by proposal and approved new version | Ordinary users denied. Administrator removal behavior for wrong/obsolete records is not yet specified | Cannot be published by a user; approval makes a pending manual record shared | Not applicable; catalogue is not user-owned | Record remains; submitting-user reference is anonymized or removed | Imports/source versions, accepted changes, and provenance require audit/history | DEC-009, DEC-010, DEC-011, DEC-012, DEC-013 | NUT-01, NUT-03, NUT-05, NUT-06, NUT-09 through NUT-11, DEP-08 | Ordinary users cannot directly edit/delete barcode imports. Catalogue visibility to logged-out users requires confirmation. |
| Pending manual catalogue additions | Planned | Submitter owns access while pending; target catalogue owns record after approval | Submitting user | Submitter and administrator only | Denied | Denied unless the viewer is submitter | Allowed: view and use while pending; edit/withdraw before decision is not yet specified | Denied | Allowed: view, approve, or reject; no decision without administrator authorization | Authenticated user submits a non-barcode item; barcode prohibited | Submitter and administrator only | Not yet specified for submitter; administrator does not edit it as user content but records a moderation decision | Withdrawal/delete before decision not yet specified; rejection preserves dependent recipe review state | Cannot be shared/published; approval makes it shared catalogue data | Not applicable; approval changes catalogue status, not ownership transfer | Pending submission permanently removed after recovery period; approved record follows shared-catalogue row | Submission, review, decision, and dependent-reference outcome are auditable | DEC-009, DEC-010, DEC-011, DEC-013 | NUT-08, NUT-09, FND-04, FND-05, DEP-08 | Duplicate/merge handling is not selected while DEC-011 is unresolved. Rejection never silently substitutes another item. |
| Catalogue correction and OpenFoodFacts refresh proposals | Planned | User proposal: proposer. Provider refresh: application | Proposer or refresh process | Proposer and administrator only until decided | Denied | Denied, except proposer may view own proposal | Allowed: create/view own proposal; amend/withdraw behavior not yet specified | Denied | Allowed: view queue and accept/reject; acceptance creates a new catalogue version | Authenticated users may propose corrections; authorized job stages refreshes | Proposer and administrator only; accepted result is visible through catalogue record | Proposer cannot mutate catalogue; proposal edit not yet specified | Proposal withdrawal/deletion not yet specified; decision history subject to DEC-013 | Cannot be shared/published; administrator acceptance publishes a new current catalogue version | Not applicable | Pending owner proposals are removed after recovery; accepted catalogue version remains without identifying submitter; decision audit follows AUDIT_RETENTION_SCHEDULE.md | Before/after values, reason, actor, base version, decision, and resulting version are auditable | DEC-009, DEC-010, DEC-013 | NUT-10, NUT-11, FND-04, FND-05, DEP-08 | Stale proposals require review. Moderation escalation/service levels remain DEC-010. |
| Private or selected-user meal plans | Planned | Plan owner | Plan owner | Owner only | Denied | Denied unless explicitly selected | Allowed: create, view, edit, delete, share, revoke, and copy | Selected users: read-only plan/snapshot view and allowed to make an independent private plan copy; edit and reshare denied | Proposed: private view/edit/delete denied unless acting as owner | Authenticated user creates own reusable or dated plan | Owner; selected registered users only while share is active | Owner only | Owner only; deletion/revocation must not delete independent copies | Owner may grant/revoke selected-user read-only access; acknowledgement required if private recipe snapshots become visible | Not yet specified; copying is not transfer | All non-public plans are permanently removed after recovery; shares end; independent copies survive | Sharing, acknowledgement, revocation, snapshot choice, and deletion are auditable where specified | DEC-012, DEC-013 | PLAN-01 through PLAN-09, DEP-08 | Share grants only plan and necessary pinned snapshot access, never live private recipe access or unrelated account data. |
| Public meal plans | Planned; owner-deletion outcome decided | Plan owner until deletion request; no public owner after anonymization | Plan owner | Private until owner explicitly makes public | Allowed read-only while public; after deletion request, only a retained unlisted plan remains accessible at its existing URL | Allowed read-only while accessible; authenticated viewers may bookmark an active-owner public plan and make an independent private copy | Before deletion request: edit, delete, return private, copy, and publish when safe. During recovery: no public owner authority unless the account is restored | Public viewers are read-only; authenticated viewers may make an independent private copy. Existing bookmark owners may remove bookmarks after deletion; new bookmarks and reshare authority are denied | Proposed public view only while accessible. Confirmed exceptional power to suppress or permanently remove a retained plan for privacy, security, legal, or moderation reasons; no restore, reattribution, transfer, impersonation, or recovery extension | Created as private; owner may publish only when every exposed field is public-safe | Anyone while normally public; after deletion request, existing URL/bookmarks only when another-user bookmarks remain | Owner only before deletion request. Afterward ordinary edit is denied; copying creates a separate plan | Owner before deletion. After deletion request, zero-bookmark or failed-safety plans become unavailable; final bookmark removal deletes a retained plan. Administrator removal is exceptional and permanent | Owner only before deletion; publication denied if private data would be exposed. Retained plans are unlisted and cannot accept new bookmarks | Denied; copying is not transfer and retained plans cannot be reclaimed after final purge | On deletion request, a plan with no other-user bookmark becomes unavailable; a bookmarked plan is attributed only to non-linked `Former VibeDietr user`, unlisted, retained at the same URL, closed to new bookmarks, and deleted when its final bookmark is removed. Recovery during an unwaived period of up to 30 days restores all plans, ownership, attribution, and prior visibility. Final purge removes unavailable plans and recovery links; retained plans remain ownerless and cannot be reclaimed. Independent copies survive | Publication, bookmark changes, visibility, copies, safety validation, deletion/anonymization, recovery, and administrator removal are auditable subject to DEC-013 | DEC-012, DEC-013, DEC-014 | PLAN-01, PLAN-08, PLAN-09, DEP-08 | Retention is allowed only when the whole plan is proven public-safe. It keeps only necessary pinned snapshots, never live private recipes, diary/consumption data, targets, private one-off items, personal organisation, account metadata, or other unauthorized fields. Any uncertainty makes the entire plan unavailable. Existing raw links to a zero-bookmark plan stop working. |
| Public plan bookmarks | Planned by DEC-014 | Bookmarking user | Bookmarking user | Owner only; aggregate existence may keep a former owner's plan available but does not reveal bookmark-owner identity | Denied | Denied | Allowed: add, view, and remove own bookmark while permitted; after source-owner deletion request, removal remains allowed but adding is denied | Denied | Proposed: no direct bookmark access or mutation; exceptional plan suppression/removal may invalidate the reference and must be audited | Authenticated user may bookmark an accessible public plan only while its owner account is active; idempotency is not yet specified | Bookmark owner only; source-plan viewing is authorized separately | No content edit; bookmark owner may only remove and, before source-owner deletion, recreate as allowed | Bookmark owner only. Removing the final bookmark from a retained anonymized plan deletes that source plan | Denied; a bookmark is neither sharing nor copying | Denied | Removed with the bookmarking user's private data. If this removes the final qualifying bookmark from a retained plan, that plan is deleted. Whether a bookmark owned by an account in its own recovery period remains `active` before purge is not yet specified | Creation/removal, qualifying-bookmark count transitions, retained-plan deletion, recovery restoration, and exceptional invalidation are auditable subject to DEC-013 | DEC-012, DEC-013, DEC-014 | FND-03, PLAN-08, PLAN-09, DEP-08 | A plan copy does not count as a bookmark. After the source owner requests deletion, no new bookmark may be added. The retained plan notice exposes no bookmark-owner identity. |
| Plan shares | Planned | Source plan owner | Source plan owner | Selected users only; public visibility/public-link access is a plan state rather than a transferable grant | Denied for selected-user share; public plan access follows public-plan row | Allowed only when named and unrevoked | Allowed: create, inspect, and revoke selected share | Allowed: read source plan and scoped snapshots; copy plan; denied edit and reshare | Proposed: denied unless acting as plan owner; security/support access not specified | Plan owner only | Owner and named recipient for selected share; direct URL by anyone else denied. Public-link access follows public-plan state | Owner may change/revoke selected grant; recipient denied | Owner revokes selected share; deletion of source plan ends it. Existing public-link access survives owner deletion only for a retained bookmarked plan | Cannot be reshared by selected recipient; public publishing remains owner only | Denied | Selected shares are removed with the non-public source plan. Under DEC-014, an existing public link remains valid only while the anonymized plan is retained by at least one qualifying bookmark and passes whole-plan safety validation. Independent recipient-owned copies survive | Grant, acknowledgement, revocation, public-link retention, and copy are auditable subject to DEC-013 | DEC-013, DEC-014 | PLAN-08, PLAN-09, FND-05, DEP-08 | Revocation is immediate for selected-source access and does not revoke an independent copy. A retained public link does not expose former ownership or private data. |
| Standalone recipe shares | Not applicable to specified product | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | None | FND-03, REC-06, PLAN-08 | No direct selected-user recipe-share resource is planned. Recipes are public/private; the only narrow private-recipe grant is a plan's pinned snapshot. |
| Plan days, slots, planned entries, and planned nutrition snapshots | Planned nested resources | Plan owner | Plan owner | Inherit plan visibility, with field minimization | Allowed only inside a public or retained-unlisted plan; private recipe content is prohibited | Allowed only inside an accessible plan | Allowed: create/edit/delete before consumption, choose whether to update pinned recipe version, and retain an existing snapshot | Read-only inside share; copy produces independent entries; no mutation of source | Proposed: no private access or mutation | Owner only inside own plan; drafts cannot be added | Inherit plan access and snapshot scope | Owner only | Owner only before deletion request; source cleanup preserves independent-copy data | Cannot be independently shared/published | Not applicable independently | Follows the source plan. A retained anonymized plan keeps only the public-safe pinned snapshots required to display and copy it; live recipe links work only while the current recipe is independently public. Any unprovable public-safety state makes the whole source plan unavailable. Independent copies survive | Snapshot/version selection, safety validation, update/retain choice, anonymization, and cleanup are auditable subject to DEC-013 | DEC-013, DEC-014 | PLAN-02 through PLAN-04, PLAN-07 through PLAN-09 | Recipe publication changes do not silently update entries. Legitimately public pinned snapshots remain usable after the live recipe changes or becomes unavailable, without granting access to current private recipe data. |
| Diary entries, consumed state, and consumption snapshots | Planned | Account/plan owner | Account user | Owner only | Proposed: denied even when a related plan is public | Proposed: denied unless product owner confirms selected-share diary fields | Allowed: create, view, correct, and delete subject to immutable replacement history | Proposed: denied; a plan share exposes planned content only until diary-field scope is confirmed | Proposed: denied private view/edit/delete unless a separately authorized purpose is confirmed | Account user for own dated plan/ad-hoc diary | Owner only under proposed sensitive-data default | Owner only; corrections create history rather than rewriting the historical snapshot | Owner only, subject to audit/retention requirements | Denied; diary data is never independently shared or public under proposed default | Not yet specified; no transfer workflow is planned | Permanently removed after recovery period | Consumption, reversal, correction, and snapshot replacement are auditable | DEC-012, DEC-013 | PLAN-05, PLAN-06, PLAN-12, DEP-08 | `PRODUCT_SPEC.md` says diary data is private unless the applicable plan is shared but does not define exposed fields. Product owner must confirm whether any consumed status, actual quantity/time, or totals are visible to selected viewers. |
| Private one-off custom plan/diary items | Planned nested resources | Account/plan owner | Account user | Owner only | Denied | Proposed: denied; planned presentation inside a selected share is not yet specified | Allowed: create, view, edit, and delete within own entry | Proposed: denied until exact selected-plan presentation is confirmed | Proposed: denied unless acting as owner | Owner creates inside own plan/diary entry | Owner only | Owner only | Permanently removed with entry/account private data | Denied; catalogue submission is a separate explicit workflow | Not applicable independently | Permanently removed after recovery period | Consumption snapshot audit applies when consumed | DEC-012, DEC-013 | PLAN-04 through PLAN-06, DEP-08 | A one-off item never becomes shared catalogue data automatically. |
| Nutrition target profiles and dated target phases | Planned | Account user | Account user | Owner only | Denied | Proposed: denied even when a related plan is shared or public | Allowed: create, view, edit, delete, assign phases, and compare own values | Proposed: denied; no target-sharing requirement is confirmed | Proposed: denied private view/edit/delete unless a separately authorized purpose is confirmed | Authenticated user for own account; one default profile | Owner only | Owner only | Owner only, preserving historical phase values needed for owner's history until account purge | Denied; targets are never public under proposed default | Not yet specified; no transfer workflow is planned | Permanently removed after recovery period | Target/profile changes and historical phase selection require history where needed for stable comparisons | DEC-012, DEC-013 | PLAN-10 through PLAN-12, DEP-08 | The product owner must confirm whether selected plan viewers see any target comparison; proposed default is denial. |
| Weight or progress records | Not applicable; absent from current product specification and roadmap | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | None | None | If later added, treat as sensitive owner-only data until a new confirmed matrix row is approved. |
| Dietary-constraint records | Not applicable; mentioned only as an absent capability, not a planned resource | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | Not applicable | None | None | If later planned, add a row before implementation; do not infer sharing from meal-plan access. |
| Recipe import requests and retained source provenance | Planned | Importing user | Importing user or authorized job acting for user | Owner only; resulting recipe is a private draft | Denied | Denied | Allowed: submit, view status/result, retry where safe, cancel where supported, and edit resulting draft | Denied | Proposed: denied content access; operational failure metadata only if separately authorized and minimized | Authenticated user for own account | Owner only | Owner edits resulting draft, not extraction provenance | Request and private provenance follow draft and account purge; terminal operational metadata is deleted after 30 days | Cannot share or publish import request; only reviewed recipe can later be finalized/published | Denied | Private draft/provenance removed after recovery; terminal metadata follows the 30-day operational clock | Provider, source, attempt, result, and draft linkage require provenance/audit without retaining unnecessary source content | DEC-005, DEC-006, DEC-007, DEC-012, DEC-013 | REC-15 through REC-17, FND-09, DEP-08 | Imports never publish automatically and preserve original wording and source attribution. |
| OCR, document, photograph, and scan source files | Planned transient resource | Uploading user | Uploading user | Owner and authorized processing service only | Denied | Denied | Allowed: upload for own import and view only where review requires it; download/reuse is not specified | Denied | Proposed: no content access; narrowly authorized security processing must be specified and minimized | Authenticated user uploads for own import, subject to validation | Owner/processing service only during processing; direct object URL access by others denied | Denied; replacement is a new upload | Automatically hard-deleted after success or failure; not retained as recipe attachment | Denied | Denied | Deleted after processing, normally before account deletion; deletion must also occur on failure | Creation, processing, and verified deletion event logged without raw file content | DEC-005, DEC-006, DEC-007, DEC-012, DEC-013 | REC-17, FND-09, DEP-03 | Provider choice/privacy remains unresolved. Transient deletion is confirmed; terminal files are deleted within 24 hours, abandoned files within seven days; backup behavior is not. |
| Import processing records and job metadata | Planned | Importing user for access; application for operation | Application job acting for importing user | Owner only for user-facing status; operational details restricted | Denied | Denied | Allowed: view own status/result and invoke allowed retry/cancel actions | Denied | Proposed: administrator/operations access only to minimized failure metadata, not raw private content | Created only for an authorized user's import | Owner sees minimized own status; authorized operations access not yet specified | User cannot rewrite provenance/job history; allowed retry creates/advances controlled state | Terminal operational metadata is deleted 30 days after completion | Denied | Denied | User-facing record removed with import/account; minimal terminal operational metadata is deleted after 30 days under DEC-013 | Correlation, attempts, failures, and cleanup must be auditable without leaking inputs | DEC-005, DEC-006, DEC-007, DEC-013 | FND-09, REC-15 through REC-17, DEP-04, DEP-05 | Operational access and retention follow DEC-013 purpose separation and minimisation. |
| Account data exports and generated export files | Planned | Requesting account user | Authorized export job acting for user | Owner only | Denied | Denied, including users who can view shares | Allowed: request, inspect status, and download own unexpired export | Denied | Proposed: no export-content access; minimized operations access only if separately authorized | Authenticated owner only | Authenticated owner only through expiring download | Export content is immutable; owner may request a new export | Generated files are deleted seven days after becoming ready | Denied | Denied | Requests/files removed on purge or earlier cleanup; export must not preserve data that should be erased | Request, generation, download, expiry, and deletion are auditable | DEC-008, DEC-012, DEC-013 | DEP-07, FND-09, DEP-08 | Export includes owned data and excludes another user's private data merely viewable through shares/bookmarks/remixes. |
| Audit records | Current FND-05 store and policy foundation; purpose-specific views remain planned | Application; no user ownership | Trusted application code records an allowlisted actor category | Internal by purpose; no production browser | Denied | Denied; a filtered activity projection and controlled rights response remain planned | No direct audit-store access; filtered activity remains planned | Denied | Current: administrators may read an individual moderation/catalogue-purpose event; administrator status grants no security or privileged-lifecycle access. Later duty-scoped roles remain planned | Current append-only allowlisted recorder only; protected legal evidence uses a separate future store | Current individual-event policy only; monitored purpose-specific views/exports remain planned | Denied; corrections are linked events | Ordinary APIs denied; approved retention/anonymization jobs and scoped holds remain planned | Denied | Denied | Final purge must destroy ordinary actor/subject mappings; minimized anonymous events may complete their approved clock, while protected evidence follows its separate statutory/hold schedule | Required and data-minimized for security, catalogue/moderation, admin decisions, versions/lineage, snapshots, anonymization, deletion verification, and user-visible activity | DEC-013 | FND-05, DEP-08, DEP-09 | The store now enforces allowlisted classifications/payloads, erasable identity mappings, UTC ULIDs, application append-only behavior, and HMAC integrity checking. Retention execution, filtered activity, monitored exports and protected evidence remain deferred. |
| Administrator status, pending promotions, bootstrap and recovery state | Current FND-14 lifecycle; factor-recovery extensions remain FND-13 | Application security state; the account remains owned by its user | Initial/recovery operator or active administrator initiating a pending promotion | Administrator/security restricted; a target may view their own pending promotion | Denied | Denied except the target's own pending promotion | Target may accept or decline an authorized pending promotion with their own authentication and second factor; cannot originate promotion, self-revoke, or bypass safeguards | Denied | Active administrator may initiate/cancel promotion or revoke another administrator only with recent re-authentication and second factor; cannot revoke self or final administrator | Initial bootstrap and break-glass recovery are configured CLI-only operator actions; routine promotion is created only by an active administrator and remains pending for target acceptance | Active administrators may view lifecycle state; target may view only their own pending request | Only controlled bootstrap, acceptance, decline, cancellation, expiry, revocation, and recovery transitions | Direct deletion denied; revocation follows the last-admin, acting-admin, audit, notification, and session-invalidation rules | Denied | Denied; administrator status is not transferable by ordinary account action | Sole administrator cannot request deletion. After a replacement is active, account deletion follows the account row; the bootstrap-completed marker remains set and audit retention follows DEC-013 | Every attempt and transition is secret-free, correlated, audited, and security-notified as required by DEC-009 | DEC-009, DEC-013, DEC-015, DEC-016 | FND-04, FND-05, FND-11 through FND-14, DEP-02, DEP-08 | Multiple administrators are supported. Production activation requires verified email, a confirmed RFC 6238 TOTP factor, and acknowledged recovery codes. Pending routine promotion expires after 24 hours; initial and break-glass assignment do not require target acceptance. |
| Administrator security-notification destinations, intents, and provider-acceptance records | Planned | Destination: account user. Delivery evidence: application security state | Verified account user, authorized lifecycle transition, or trusted delivery process | Security restricted | Denied | Denied | Affected account may verify and view its own destination state; cannot disable mandatory administrator notification | Denied | Active administrators receive only notifications assigned by DEC-016; administrator status alone grants no browser for another account's destination, message body, or delivery records | Created only by signed destination verification or an authorized, correlated lifecycle transition | Affected account may view its own destination status; operational evidence is duty-scoped and not exposed by ordinary administrator status | Destination changes require recent re-authentication, fresh TOTP, new-address verification, and old/new notification; delivery evidence is append-only | No direct deletion; destination replacement and approved retention cleanup use controlled transitions | Denied; notifications do not grant resource access | Denied | Destination data is removed with the account subject to approved security-audit retention; retained evidence uses opaque references where sufficient | Every required intent, provider acceptance/refusal, terminal failure, destination verification, and destination change is secret-free, minimized, correlated, and auditable under DEC-016 | DEC-009, DEC-013, DEC-015, DEC-016 | FND-05, FND-09, FND-13, FND-14, DEP-02, DEP-04, DEP-08 | Provider credentials, factor/recovery material, verification tokens, message bodies, and private application content are never stored in audit payloads. Provider acceptance is not delivery or read evidence. |
| Administrator-only moderation and management records | Planned | Application | Authorized administrator or system | Administrator only | Denied | Denied | Not applicable as ordinary user ownership | Denied | Allowed only for expressly authorized moderation/managed-vocabulary actions; decisions are append-only/audited | Administrator/system only after FND-04 authorization and FND-14 activation; administrator assignment follows DEC-009 | Administrator only | Allowed only for mutable queue/management fields; recorded decisions and audit history not edited | Retention/deprecation follows record type; final audit retention follows AUDIT_RETENTION_SCHEDULE.md | Denied, except approved result becomes visible through its catalogue/tag resource | Denied | Administrator deletion removes actor attribution as permitted; record retention follows DEC-013 | Every decision, actor, subject, purpose, and timestamp is auditable | DEC-009, DEC-010, DEC-013 | FND-04, FND-05, FND-14, NUT-09 through NUT-11, REC-13 | DEC-009 decides administrator assignment and lifecycle; DEC-010 governs service levels/escalation, not basic authorization. |
| Deleted or anonymized-owner public content | Planned lifecycle state, not a new creatable resource | No active public owner after anonymization | Former creator retained only through non-identifying attribution where confirmed | Public recipe remains public; retained public plan becomes unlisted | Public recipe allowed. Retained plan allowed only through its existing URL while another-user bookmarks remain | Same read access; authenticated viewers may independently copy a retained plan but cannot add a new bookmark | Not applicable after deletion request unless secure recovery succeeds during an unwaived period of up to 30 days | Public read-only only; existing plan bookmark owners may remove bookmarks | Proposed moderation where defined. Confirmed power to suppress or permanently remove a retained plan; no restore, reattribution, transfer, impersonation, or recovery extension | Not applicable | Public recipe: allowed. Approved catalogue contribution: follows catalogue. Retained plan: existing URL only while bookmarked and public-safe | Ordinary edit denied; correction/moderation uses the source workflow | Public plan is deleted after its final bookmark or a failed safety check; other retention follows the source resource | Cannot restore former ownership by sharing; ordinary viewers cannot reshare private data | Denied; no transfer to another account | Public recipes remain anonymized; approved catalogue records retain no identifying submitter. A bookmarked public plan is immediately anonymized as non-linked `Former VibeDietr user`, unlisted, closed to new bookmarks, and retained only while an existing bookmark remains. Zero-bookmark and unsafe plans become unavailable immediately. Recovery can reattribute and restore them only during that unwaived period; after purge, retained plans cannot be reclaimed | Anonymization, bookmark lifecycle, safety validation, retained attribution, recovery, and removal are auditable subject to DEC-013 | DEC-012, DEC-013, DEC-014 | DEP-08, FND-05, REC-14, NUT-01, PLAN-08, PLAN-09 | Retained content must never route to former private account data. Broader inactive-data retention is future policy. |

FND-13 implements the second-factor records and notification-intent/provider-
acceptance foundations described above. Authenticated, active, email-verified
users may manage only their own pending enrollment and recovery material;
enrollment grants no role. Guests and other users are denied by the
authenticated owner boundary.

FND-14 implements the lifecycle row, pending-promotion state, CLI-only bootstrap
and break-glass commands, routine promotion actions, revocation, session and
remembered-login invalidation, and account-deletion guard. Active
administrators see lifecycle state needed to initiate/cancel promotion and
revoke another administrator; a target sees only their own requests and may
accept or decline. Ordinary users cannot initiate or revoke, no browser route
can bootstrap or perform break-glass, and administrator status alone still
grants no security-audit browser or another account's delivery state.

## 6. Required scenario rulings

1. **Logged-out access:** Logged-out visitors may read only explicitly public
   finalized recipes, enabled public profile fields, and public meal plans.
   Drafts, private recipes/plans, selected shares, catalogue proposals,
   organisation, diary, targets, imports, exports, audit data, and admin data
   are denied. Logged-out approved-catalogue browsing is not yet specified and
   is proposed denied until confirmed.
2. **Private recipe through a plan:** A public plan cannot be published while
   it contains a private recipe that the plan would expose. A selected-user
   plan may expose a read-only pinned private-recipe snapshot only after the
   plan owner explicitly acknowledges that consequence.
3. **No automatic underlying access:** Access to a plan never grants access to
   the live private recipe, other recipe versions, the creator's profile,
   organisation, diary, targets, or other account data. It grants only the
   snapshot fields required to understand that plan.
4. **Shared-user capabilities:** A selected plan viewer may view the shared
   plan and scoped snapshots and may create an independent private plan copy.
   They may not edit the source, edit the source recipe, copy the private
   recipe as a standalone recipe, transfer ownership, or reshare the source.
   The last two denials follow the proposed deny-unless-granted principle where
   the specification is silent.
5. **Shared catalogue mutation:** Ordinary users cannot edit or delete
   barcode-imported shared records. They submit correction proposals. Edit and
   removal behavior for approved manual records is not yet fully specified and
   must use proposal/moderation rather than direct ordinary-user mutation until
   confirmed.
6. **Catalogue proposal review:** Pending manual additions are visible only to
   submitter and administrator. User corrections and provider refreshes are
   staged. An authorized administrator accepts or rejects; acceptance creates
   a new current version, rejection retains the current catalogue data, and
   every decision is auditable. DEC-010 still governs escalation/service
   levels and DEC-011 governs duplicate/merge behavior.
7. **Bookmarks after source changes:** A bookmark points to the live finalized
   public recipe. When that recipe is deleted or made private, the bookmark
   remains as an unavailable tombstone and exposes no recipe content.
8. **Plans after recipe changes or deletion:** A plan pins a recipe version and
   snapshot. Publishing a newer version never silently changes it; the plan
   owner reviews whether to update or retain. Deleting/unpublishing the source
   leaves the existing plan snapshot usable within the plan's authorization
   boundary.
9. **Owner deletion:** The confirmed public-plan transition begins when
   deletion is requested, not at the end of recovery. A public plan with an
   active bookmark owned by another user is immediately anonymized as
   non-linked `Former VibeDietr user`, unlisted, left at its existing URL,
   closed to new bookmarks, and available for independent private copying. A
   zero-bookmark plan becomes unavailable immediately. Removing the last
   existing bookmark deletes the retained source plan. Recovery during an unwaived period of up to 30 days
   restores ownership, attribution, unavailable plans, prior visibility, and
   ordinary bookmarking. Final purge deletes unavailable plans and recovery
   links; a retained plan then cannot be reclaimed. It retains only proven
   public-safe pinned snapshots, and any uncertainty makes the entire plan
   unavailable. Independent remixes and plan copies survive.
10. **Administrator view of private content:** Not yet specified. Proposed
    default: denied unless the administrator is also the owner or a narrowly
    defined security/support purpose and access rule is later approved.
11. **Administrator edit of user-owned content:** Proposed default: denied.
    Administrators moderate catalogue proposals and managed vocabularies; they
    do not impersonate owners or directly edit recipes, plans, diary, targets,
    imports, or profiles.
12. **Diary, targets, and progress privacy:** Logged-out access is denied.
    Proposed default is owner-only, including denial through public plans.
    Weight/progress records are not currently planned. Product-owner
    confirmation is required for which, if any, diary or target comparison
    fields a selected plan viewer may see.
13. **Imports and source material:** Import requests, drafts, processing state,
    and provenance are owner-only. Uploaded/OCR source files are accessible
    only to the owner and authorized processing service and are hard-deleted
    after success or failure. They are never recipe attachments or public
    resources.
14. **Audit access:** Logged-out and ordinary non-admin access to the audit
    store is denied. Purpose-specific read-only views separate the security
    role, scoped moderator role, and a user's filtered activity projection;
    administrator status alone grants no security-log access. Application APIs
    append allowlisted events but do not edit them. Monitored exports and direct
    rights responses are restricted to the minimum authorized fields. Actor
    treatment and fixed retention follow `AUDIT_RETENTION_SCHEDULE.md`.
15. **Deletion modes:** Current accounts and ingredients are hard-deleted
    immediately, with ingredients cascading from account deletion. The planned
    product offers an optional recovery period of up to 30 days followed by
    permanent removal of specified private data, anonymization of retained
    public recipes and approved catalogue contributions, and automatic hard
    deletion of transient uploads. An authenticated user may waive recovery;
    a confirmed under-13 account is purged without recovery, subject to the
    sole-administrator safeguard and scoped incident, dispute, or statutory
    hold. DEC-014 public plans adopt their final public behavior immediately:
    bookmarked plans are anonymized and unlisted, zero-bookmark plans are
    unavailable, protected restoration remains possible during any recovery
    period, and final purge removes recovery-only links. Retained plans are
    deleted after their final bookmark. Ordinary audit identity is removed or
    mapping-destroyed at purge as scheduled; separately protected evidence is
    retained only for its documented purpose. Backup expiry remains unresolved
    under DEC-012.

16. **Initial administrator bootstrap:** Production bootstrap is CLI-only and
    requires one authenticated/traceable trusted operator, explicit environment
    enablement and exact target configuration, an active verified and
    TOTP-enrolled target, zero administrators, an unset persistent
    completion marker, operator confirmation, and successful audit evidence.
    It is atomic, never automatic or self-service, and never reopens.
17. **Administrator promotion and revocation:** An active administrator may
    create a pending promotion only after recent re-authentication and a valid
    fresh TOTP. A verified, TOTP-enrolled target gains no privilege until
    accepting with their own TOTP within 24 hours. Authorized
    cancellation/decline and expiry leave the target ordinary. Another
    administrator may revoke with the acting-admin controls only if at least one
    other active administrator will remain; self-revocation and sole-
    administrator deletion/revocation are denied, and
    revocation invalidates active sessions and privileged credentials.
18. **Administrator recovery:** Ordinary recovery first uses one mandatory
    single-use recovery code and requires replacement-factor confirmation. A
    different active administrator may initiate recovery only with immediate
    password re-confirmation and their own fresh TOTP; the affected user must
    complete enrollment. Total sole-administrator factor loss may use the
    short-lived, single-use, target-bound CLI-assisted host-access ceremony
    defined by DEC-015. It never disables TOTP or grants access directly and
    does not clear bootstrap state. Recovery invalidates old factors, codes,
    sessions, and remembered logins. Privilege events require reliable
    production security notifications; only their delivery selection remains
    unresolved under DEC-016.

## 7. Unresolved decisions and product-owner questions

The matrix does not select an answer for any of these items:

- **DEC-012:** Define backup expiry and restore-time re-erasure behavior. Live
  deletion rules above do not claim immediate physical erasure from backups.
- **DEC-011:** Define manual catalogue duplicate/merge handling and dependent
  reference behavior. No row authorizes silent merging.
- **DEC-010:** Define moderation escalation, appeal, stale-item handling, and
  service levels. Basic administrator accept/reject authority is already
  confirmed.
- Confirm whether administrators ever receive narrowly scoped access to
  private user content for support/security, and if so define purpose,
  approval, audit, notification, and field minimization.
- Confirm whether a selected-user plan share exposes any consumed state,
  actual quantity/time, target, or target-comparison fields. Proposed default
  is that it does not.
- Confirm whether approved shared catalogue browsing is available to logged-out
  users. Proposed default is authenticated read-only access.
- Confirm whether a public-plan bookmark owned by a different account remains
  `active` while that bookmarking account is in its own 30-day recovery period,
  or stops qualifying when its deletion is requested.
- Define submitter edit/withdraw behavior for pending manual catalogue items
  and proposals, administrator removal behavior for obsolete catalogue data,
  and retention of unreferenced superseded recipe versions.
- Confirm whether any ownership-transfer workflow is needed. None is currently
  planned; remixes and plan copies are independent copies, not transfers.

## 8. Policy-test checklist

Every future authorization-policy change must reference the corresponding
matrix row in its test name, test documentation, commit, or pull request and
must update this document when product behavior changes.

For every applicable resource row, future policy coverage must instantiate
all applicable checks below through both successful and denied paths:

- Owner can perform every action marked allowed.
- Owner cannot perform each action marked denied, not applicable, immutable,
  moderation-only, or otherwise prohibited.
- An authenticated non-owner is denied by default.
- A shared user receives only the exact granted view/copy permissions and no
  edit, ownership-transfer, or reshare authority.
- A logged-out user can access only content explicitly public in the row.
- Administrator access and mutation exactly match the row, including denial
  where administrator access is only proposed or not specified.
- A private nested resource is not exposed through a public parent, serialized
  relationship, identifier, search result, preview, or error response.
- Behavior after owner deletion matches the row, including anonymization,
  tombstones, bookmark-qualified retention, snapshot/copy survival, share
  revocation, recovery restoration, and final deletion outcomes.
- Direct URL/object-key/model-identifier access is denied wherever list or UI
  access is denied.
- Livewire actions enforce the same rules at the mutation boundary as
  conventional controller actions.
- API and JSON endpoints, including Livewire payloads and serialized nested
  data, enforce the same rules as page requests.

Resource-specific future checklist:

| Resource group | Required assertions in addition to the common checklist |
| --- | --- |
| Accounts/private profiles | Guest and non-owner cannot read profile/email; owner-only update/export/deletion request subject to sole-administrator denial; replacement-first, password and recovery checks; optional recovery up to 30 days plus waiver and confirmed-under-13 immediate purge; per-resource purge/anonymization; administrator private-profile denial remains proposed. |
| Public attribution profiles | Disabled profile is not directly enumerable; enabled profile omits email/private resources; disabling profile does not unpublish recipes; deleted owner is anonymized. |
| Recipe drafts and draft revisions | Guest/non-owner/admin-proposed-default denied on page, Livewire, and JSON; intended-public flag never exposes draft; draft cannot be bookmarked or planned. |
| Published recipes and versions | Guest can read current public version only; private/unpublished/draft versions denied; only creator starts/edits revision; selected plan viewer receives only pinned snapshot; source deletion preserves authorized snapshot/remix and tombstones bookmark. |
| Recipe nested content | Direct child identifiers enforce parent/version authorization; public serialization omits private provenance/account data; immutable published children reject mutation; original text survives authorized operations. |
| Remixes | Only accessible source can be remixed; remix starts private; source owner cannot edit; source removal does not break remix; lineage does not leak inaccessible source content. |
| Bookmarks and private organisation | Owner CRUD succeeds; every other actor and guest is denied; unavailable source becomes non-disclosing tombstone; public recipe/profile responses omit organisation. |
| Public/managed tags | Tag rendering inherits recipe visibility; owner cannot edit managed vocabulary; administrator can manage vocabulary but cannot edit user recipe content; suggestion review and deletion preserve authorization. |
| Current user-owned ingredients | Owner CRUD and owner-scoped list; guest/non-owner denial; controller and Livewire mutation parity; forged `user_id` rejected; hard delete/cascade characterized; restore/force-delete denied. |
| Approved catalogue | Authenticated read and ordinary-user mutation denial; proposed guest denial; submitter receives no owner mutation; accepted proposal creates visible version; submitter deletion nulls/anonymizes provenance without deleting record. |
| Pending manual additions | Submitter/admin view; every other user and guest denied; approval visibility transition; rejection/owner deletion behavior; direct URL/search/API isolation; duplicate handling waits for DEC-011. |
| Correction/refresh proposals | Proposer/admin view only; ordinary user cannot mutate catalogue; administrator accept/reject authorization and audit; stale proposal behavior; accepted version survives proposer deletion without identifying attribution. |
| Private/selected plans and shares | Owner CRUD/share/revoke; unselected user/guest denied; selected viewer read/copy only; private snapshot scope; immediate revocation; independent copy survives; diary/target fields remain denied until confirmed. |
| Public plans | Guest/authenticated read-only; only owner edits/unpublishes; publication rejected when private data would be exposed; independent copy allowed; deletion request applies bookmark-qualified retention, immediate anonymization/unlisting, disabled new bookmarks, stable URL, last-bookmark deletion, recovery restoration, final non-reclaimability, and whole-plan fail-closed safety checks. |
| Public plan bookmarks | Owner-only create/view/remove; guest/non-owner/admin-proposed-default denial; creation allowed only for an active-owner public plan; no new bookmark after source-owner deletion request; bookmark-owner identity never disclosed; final qualifying removal deletes retained plan; copies do not count; bookmark-owner deletion/recovery behavior follows the matrix and the remaining `active`-status question. |
| Plan entries/snapshots | Draft recipe rejected; pinned version stable after publication/deletion; direct nested access inherits plan; shared copy is independent; no private recipe beyond the pinned snapshot. |
| Diary/consumption/one-off items | Owner allowed actions; guest/non-owner/shared viewer/proposed-admin denied; public plan serialization omits consumed/target data; correction history is authorized; purge removes private history and one-off data. |
| Nutrition targets/phases | Owner CRUD/assignment; all other actors denied under proposed default; no target leakage through plan/public totals; historical applicable version remains stable until purge. |
| Recipe imports | Owner create/status/retry where allowed; guest/non-owner denied; resulting draft private; failed jobs do not leak input; no automatic publication. |
| Uploaded/OCR source files | Authorized upload/processing only; guessed URLs and stale signed URLs denied; no public attachment route; verified deletion after success, failure, and timeout; logs/audit omit file content. |
| Import job metadata | Owner sees minimized own status; cross-user IDs and JSON denied; operator access follows approved rule; retry is idempotent and cannot change owner; retention follows future decision. |
| Account exports | Owner-only request/download; share access never adds another user's private data; expiring URL cannot be reused by guest/non-owner; generated-file cleanup and audit; no administrator content access under proposed default. |
| Administrator lifecycle | Guest/ordinary-user initiation denied; bootstrap/recovery CLI-only; configured target, verification, second-factor enrollment, zero-admin/marker, concurrency, audit and notification failure checks; promotion acceptance/cancellation/decline/expiry; multiple/last/self revocation; sole-admin deletion denial; session invalidation; break-glass replacement; local/test isolation; secret-free evidence and notifications. |
| Audit/admin records | Guest/ordinary user denied; only expressly authorized administrator actions allowed; append-only mutation; direct event URL/API denial; actor deletion and retention tested against the DEC-013 schedule; every DEC-009 assignment/lifecycle path and refusal tested after DEC-015/DEC-016 implementation. |
| Deleted/anonymized content | Former private content is inaccessible through direct/nested routes; public recipe/catalogue attribution is non-identifying; independent copies/remixes survive; retained plan exposes only approved snapshots through its existing URL, accepts no new bookmark, is removed after its final bookmark, and cannot be reattributed after purge. |
| Not-applicable resources | Assert no standalone recipe-share, weight/progress, or dietary-constraint endpoint is accidentally exposed if similarly named infrastructure is later introduced; add a full matrix row before implementing such a feature. |

No automated tests are created by this documentation task.

## 9. Out of scope

This task does not:

- Implement Laravel policies or gates.
- Modify authentication.
- Add roles or permissions packages.
- Add administrator accounts.
- Change database ownership.
- Publish or share existing user data.
- Alter routes or middleware.
- Resolve any other open product decision.
- Create automated policy tests.
- Authorize production data changes.

It also does not implement retention jobs, deletion workflows, exports,
moderation, sharing, or any other planned application behavior described by
the matrix.
