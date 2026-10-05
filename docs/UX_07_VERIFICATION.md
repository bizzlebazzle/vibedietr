# UX-07 accessibility remediation verification

UX-07 remains **P2**. On 2026-10-04 the product owner confirmed UX-01 through
UX-06 complete; their inconsistent roadmap status wording was corrected before
implementation. The task-context command found no unresolved decision blocker.
This record distinguishes implemented remediation, repeatable browser evidence,
manual inspection, and outstanding human verification. It is not a WCAG
conformance claim. The reference is [WCAG 2.2](https://www.w3.org/TR/WCAG22/);
[W3C's evaluation guidance](https://www.w3.org/WAI/test-evaluate/tools/)
explains why tools alone cannot establish accessibility.

## Scope and resolved defects

Inspected routes, shared Blade/Livewire primitives, and existing tests before
editing. Authorization, ownership, nutrition precision/provenance, recipe text,
snapshot history, moderation proofs, and immediate account-deletion behavior
retain their existing boundaries. No migration or new product feature is added.

- Authentication: login, registration, password reset/request/confirmation,
  email verification, and two-step setup/security presentation. Guest pages now
  have a main landmark, page heading, descriptive title and skip destination.
  Authentication and profile errors use UX-02 summaries and field descriptions.
- Catalogue: discovery, approved/pending/rejected details, manual submissions,
  corrections, and legacy ingredient layouts. Shared primary-button text and
  dark catalogue metadata contrast were corrected. Form boundaries remain
  distinguishable, including native selects and fields outside the planning UI.
- Recipes: authoring, ingredient matching, ordering, saved detail/resizing,
  discovery, imports and recovery guidance. Heading levels and candidate names
  were corrected. Scroll clearance prevents the sticky save bar from covering
  keyboard focus. Original wording and optional match review remain intact.
- Planning/sharing: owner forms, selected-user read-only views, copying,
  revocation, nutrition targets, and UX-05 entry/consumption controls. Page and
  section headings now follow the same structure as other feature pages.
- Profile/navigation: theme buttons expose pressed state, a visible checkmark,
  and contrasting selected colors. Dropdowns expose control relationships, dismiss
  with Escape and return focus; responsive navigation also returns focus.
  Logout is a button rather than an anchor nested inside a button.
- Moderation: queue filtering, submission inspection, decision/authentication
  forms and controlled terms. Headings and validation summaries are consistent;
  field errors remain scoped rather than marking all fields invalid. Invalid
  UTF-8 separators in two moderation views were repaired.
- Deletion/export: the existing password-confirmed deletion dialog isolates its
  background with native `inert`, traps focus including a focused error summary,
  restores the stable opener after a Livewire render, and retains cancellation.
  Actions wrap and the close target is enlarged. Self-service account export
  and delayed deletion are unimplemented DEP-07/DEP-08 work; UX-07 does not add
  them. Recovery-code printing retains its existing control and semantics.
- Shared focus outlines cover native and component controls. UX-02's polite
  success text still hides on its existing timer but no longer fades through
  unreadable contrast. Reduced-motion styling remains centralized.

## Repeatable automated evidence

Run the documented Sail commands in [README](../README.md#tests-and-quality-checks).
The testing-only server/fixtures use additive migrations in MySQL `testing`,
never reset the database, and clean only their synthetic records. No live food,
import or email provider is called. Run browser suites sequentially with PHPUnit
because they share the testing database and port 8015.

- `npm run test:accessibility`: 15 real Chromium tests, 105 representative
  full-page/state axe scans in light/dark themes, **zero violations**, including
  zero serious/critical findings. The initial 54 scans recorded 98 serious
  contrast-node incidences across repeated pages/themes, plus missing landmarks
  and heading defects. This counts occurrences, not distinct defects.
- Guest, owner, reader and administrator states include empty collections,
  pending/rejected food, source nutrition, ingredient-estimate limitations,
  failed/reviewable imports, form errors, open/invalid/cancelled deletion dialogs,
  sharing/copy/revocation and success feedback. Browser assertions check one
  main/primary heading, unique IDs, valid label/ARIA references, accessible names,
  and absence of nested interactive controls. These are focused markup checks,
  not a general HTML validator.
- Real Tab/Shift+Tab/Enter/Space/Escape journeys cover skip/navigation, failed
  login and recovery, recipe edit/save/reorder/match/cancel-clear/resize,
  catalogue validation/submission, sharing/read-only copy/revocation, profile
  save/theme state, and deletion validation/cancel/success. Focused controls are
  checked for obscuration. The modal is named in Chromium's accessibility tree
  and background navigation is absent while it is open.
- Rendered primary-button text is checked at 4.5:1 and field/focus boundaries at
  3:1 in both themes. Axe checks field error text in representative error states.
  Color is supplemented by wording, pressed/expanded state, and error semantics.
- Actual browser zoom is asserted through device pixel ratio at 200% and 400%;
  viewport/content bounds check representative dense forms. A 320 CSS-pixel
  dialog and reduced-motion media preference/transition duration are tested.
  UX-05's separate suite retains native-form, touch, target/history, consumption,
  correction/reversal, nutrition-state and planning reflow coverage.
- Six cropped PNG baselines in `tests/Browser/snapshots` cover primary buttons,
  theme controls and modal headers in both themes. Dimensions and pixels are
  compared on every run; baselines were explicitly reviewed. Larger screenshots
  are inspection artifacts, not pixel baselines.
- `AccessibilityRemediationTest.php` covers guest layout structure, failed
  authentication/input retention, semantic navigation/modal state and guest/
  ordinary-user/administrator boundaries. Existing affected feature tests and
  frontend feedback/contrast/motion regressions remain required.

Reports, contrast ratios and screenshots are written under the already ignored
`storage/app/testing/ux07` directory. CI uploads these artifacts and runs the
suite in `Backend tests`. Axe `incomplete` contrast checks for native select
options remain review items rather than violations; native picker rendering
requires physical-browser review.

Local final gates on 2026-10-04 passed: the complete backend suite (1,056 tests,
6,816 assertions), Pint, PHPStan, production build, documentation checks,
development-environment validation, all 24 frontend tests and all six planning
browser tests. Initial title expectations and a planning heading-level defect
were corrected before the final runs. The accessibility pixel comparison also
exposed inconsistent LCD font antialiasing after keyboard interaction; consistent
browser font rendering fixed it without increasing the pixel tolerance.
The build's existing stale Browserslist-data warning remains non-blocking.
Migration/provider/queue/deployment gates are not applicable to these UI changes.

### CI follow-up

[Quality gates run 197](https://github.com/bizzlebazzle/vibedietr/actions/runs/37224012416)
passed PHPUnit and planning checks but failed the accessibility step in
`Backend tests`: the selected theme button reached 1.96:1 contrast during its
150ms color transition. Theme buttons now change colors immediately. A focused
regression samples all three buttons at five points through light, dark and
system changes, including any active CSS transitions. It reproduced sub-4.5:1
frames before the fix and passes afterwards; `theme-contrast.json` records the
45 samples. Axe rules and visual tolerances are unchanged.

The rerun also exposed pagination defects when the moderation queue has more
than one page. Conventional Laravel and Livewire view overrides retain their
existing links/actions while giving unavailable controls valid named roles and
correcting dark count/current-page and arrow hover contrast. Fixtures now
guarantee paginated catalogue/moderation states, independent of other testing
records. First/last disabled controls have PHP coverage; desktop/mobile keyboard
pagination and seven additional full-page axe states have browser coverage.

### Browser timing regressions found during DEP-06 CI

[Quality gates run 203](https://github.com/bizzlebazzle/vibedietr/actions/runs/37355085838)
passed PHPUnit and planning checks, then failed profile deletion and a navigation
contrast scan in the accessibility suite. Navigation colors now update together
without intermediate color transitions. The theme regression samples all visible
theme/navigation text at five transition positions in each of three modes,
compositing translucent background layers; its 135 samples reproduced ratios
below 4.5:1 before the correction and pass afterward.

Delayed modal autofocus could steal focus after the user had already tabbed into
the password field. A delayed-frame keyboard regression reproduced eight typed
characters followed by focus moving to Close, so Enter closed the dialog rather
than submitting deletion. Initial autofocus now preserves focus already inside
the open panel and does not focus a closed panel. The regression retains delayed
frames and paused keyboard entry; cancellation, validation-summary focus,
background isolation, focus trapping, successful deletion and guest enforcement
remain tested. Redirect failures report focus, field length and validation state
without printing the password. Contrast thresholds and pixel baselines are unchanged.

The final local run passed all 15 accessibility tests and 105 axe scans with zero
violations, all six planning tests, and the unchanged visual baselines. The
135 theme/navigation samples stayed at or above 4.81:1. The unchanged Composer
gate passed 1,062 tests / 6,827 assertions in 84.66 seconds on a disposable MySQL
8.0 target in Sail. The persistent-database attempt hit Composer's 300-second
timeout while several schema resets each took about 44 seconds; the disposable
run retained that timeout. Pint, PHPStan, build, 24 frontend tests, documentation
checks and CI's cached production-configuration smoke check also passed locally.

## Manual inspection and outstanding verification

On 2026-10-04 the agent inspected source/markup and generated PNGs using the
image viewer. The 320px dialog exposed a split Cancel label; spacing/action
wrapping was corrected and the replacement screenshot inspected. Light/dark
primary-button, theme and close-focus baselines were inspected, along with the
400% profile screenshot. These static inspections do not constitute a human
keyboard session, live screen-reader test, or physical-device test.

| Area | Evidence recorded here | Human verification still required |
| --- | --- | --- |
| Focus order/visibility | Real key events, focused-element bounds, modal trap/return; focus screenshots inspected | Repeat full journeys and check browser chrome, sticky content and error recovery |
| Contrast/non-color | Rendered ratios, axe, light/dark screenshot and wording inspection | Native pickers, hover/focus states and all incomplete contrast nodes |
| Landmarks/structure | Source inspection, full-page axe, main/heading/ID/reference checks | Landmark and heading navigation with assistive technology |
| Names/descriptions | Associated labels, candidate names, ARIA references and modal accessibility-tree assertions | Announced names, descriptions, expanded/pressed states and reading order |
| Errors/notifications | Summary focus, preserved input, scoped invalid state, polite status markup; fading removed | Announcement timing, duplicate/missed announcements and field correction with a screen reader |
| Zoom/reflow/spacing | 320px and actual 200%/400% Chromium bounds; dialog/profile PNG inspection | Text-only resize, custom text spacing, both orientations and real mobile/native controls |
| Motion | Emulated preference, computed transition duration and centralized CSS inspection | Actual OS reduced-motion preference during navigation, modal and feedback updates |
| Keyboard journeys | Repeatable end-to-end WebDriver key events; UX-05 native/touch alternatives retained | Human keyboard-only traversal and password-manager/paste/autofill operation |
| Screen-reader journeys | Semantics and Chromium tree only | Complete all journeys below using real assistive technology |

No real screen-reader or physical-device capability is exposed in this execution
environment. A reviewer must record date, browser/OS/device and assistive
technology/version, steps, outcome and any defect for these journeys:

1. Log in with a wrong password, hear the summary/field error, correct it; use
   reset/password confirmation and verification with paste/autofill. Enroll an
   authenticator using the manual key/QR alternatives and save/print recovery
   codes without disclosing them in the verification record.
2. Search/discover a food, read its nutrient values and provenance, submit a
   manual food with an error, correct it, and inspect its pending state.
3. Edit a recipe with long original text, add/reorder/save rows, select a food,
   review estimate limitations, cancel a destructive clear, and resize details.
   Confirm missing versus zero/source versus estimate wording and unsaved input.
4. Build and consume a plan using UX-05's controls, correct/reverse/re-consume,
   read dense nutrient comparisons, share with a second test account, inspect
   read-only access/copy, and revoke the share.
5. Save profile settings, change themes, open deletion, submit a wrong password,
   hear/fix the error, cancel and verify opener focus. Perform successful deletion
   only on an explicitly disposable account.
6. As an authorized administrator, filter/inspect moderation work and exercise
   validation/confirmation with normal recent-password and fresh TOTP proofs.
   Check reasons, candidate choices, private evidence and decision feedback.

The implementation is **conditionally complete** pending this human verification
and successful remote `Backend tests`, `PHP formatting`, `Static analysis` and
`Frontend build` statuses. Local final gate results are reported at handoff;
no remote CI result or branch-protection setting is inferred from local checks.
