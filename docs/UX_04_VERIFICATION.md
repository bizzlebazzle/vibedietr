# UX-04 presentation verification

UX-04 is complete as confirmed by the product owner on 2026-10-04 and
remains P1. NUT-16 and UX-02 are complete, and DEC-002 is decided.
The implementation follows DEC-002's combined summary and inline treatment.
Review is optional and does not block saving or publication. Match confirmation
retains automatic evidence and the selected version; it does not repair quantity,
conversion, or nutrient-data gaps. Corrections remain draft/revision mutations.

## Automated coverage

- `RecipeMatchReviewUxTest.php`: creator/reader status matrix, optional review,
  actor/evidence preservation, remaining limitations, unchanged original text
  and unsaved input, stale/unavailable selections, ownership, and immutable
  published versions versus saved revision previews.
- `RecipeNutritionEstimatePresentationTest.php`: complete/partial/unavailable
  rendering, every existing exclusion's reason/remedy, affected nutrients,
  retained partial values, real zero versus missing, and reader/creator links.
- `RecipeNutritionSourcePrecedenceTest.php`: imported and overridden primary
  nutrition remains source-labelled across all estimate completeness states;
  collapsed comparisons retain their own status, line count, reasons, remedies,
  and explicit per-serving estimate label.
- `feedback.test.mjs`: deliberate focus on an ingredient's review status after
  review navigation/action, validation-focus precedence, preserved field
  descriptions, and reduced-motion support. These use DOM doubles and are not
  browser keyboard tests.
- `nutrition-presentation.test.mjs`: native disclosure/link/button semantics,
  persistent non-color wording, and actual Tailwind palette contrast for amber
  and unavailable warnings, links, and focus outlines in light/dark themes.
  These do not replace rendered-page axe or visual checks.

Run focused backend checks with Sail's `artisan test` command. The documented
`composer test -- <path>` form currently forwards the path to `config:clear`
and fails before tests start; `composer test` without arguments is the full gate.
The existing `npm run test:scanner` command includes all frontend tests above.

## Outstanding browser and external checks

At the original UX-04 handoff, browser evidence and remote CI were pending.
The product owner subsequently confirmed completion on 2026-10-04. The
repository now has Selenium/axe tooling introduced by UX-05; UX-07 extends
that coverage. The following checklist records the original verification scope,
without inventing retrospective test results.

Verify the following using the existing application in a browser:

1. In light/dark themes at narrow/mobile and desktop widths, inspect complete,
   partial, and unavailable estimates, including long original ingredient text.
   Repeat at 200% zoom; confirm text wraps and controls do not overflow.
2. Inspect imported and creator-override primary nutrition with each comparison
   completeness state. Primary values must retain source labels. The collapsed
   comparison summary must expose its estimate status and attention count.
3. Using Tab, Shift+Tab, Enter, and Space, open the attention disclosure and
   follow correction links. Confirm focus reaches the named ingredient's review
   status and unsaved edits survive local navigation and ordinary updates.
4. Keep a reviewable food; confirm one polite outcome/count announcement and
   focus on its updated status. Remaining conversion/nutrient limitations must
   remain. Search to replace, select another food, and cancel/confirm clearing;
   confirm original text survives and clearing exposes unmatched/excluded state.
5. With a screen reader, verify ingredient-specific action names, associated
   warning explanations, disclosure state, missing versus zero values, and
   validation-summary focus. A review warning alone must not mark fields invalid.
6. As a guest/non-owner, inspect public limitations and confirm source-recipe
   correction controls are absent. As the owner, compare saved draft/revision
   preview with published detail; the published match stays unchanged until
   publication. Verify reduced-motion settings and capture representative visual
   states with available browser tooling when that tooling is established.

Remote `Backend tests`, `PHP formatting`, `Static analysis`, and `Frontend build`
statuses remain required before merge. This task does not publish or push a PR.
