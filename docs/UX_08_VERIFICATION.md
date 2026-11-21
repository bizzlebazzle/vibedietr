# UX-08 recipe navigation and barcode matching

Implemented on branch `recipe-ux-fixes`. The latest full accessibility and
planning browser suites pass after the recipe-editor quantity precision fix. The
earlier deletion-dialog transition finding did not recur; that flow was not
changed. Physical-device and assistive-technology release checks remain pending.

## Behavior and boundaries

- `/recipes` defaults to public discovery; authenticated
  `?view=mine|drafts|bookmarks` provides owner-only library views. Invalid
  selectors are rejected. `/bookmarks` redirects while preserving its page.
- Finalized lists include public/private recipes and immutable current titles.
  Draft lists include initial drafts and active revisions. Revisions appear in
  both personal lists; dashboard counts explain the overlap.
- Saved ingredient barcode lookup uses the existing OpenFoodFacts client,
  shared catalogue importer, hashed user/IP and global limits. It checks
  recipe/line authorization first and never creates a legacy ingredient.
  Existing approved barcodes skip the provider; failed or unavailable products
  are not offered. Explicit food selection preserves existing match rules.
- Typed lookup and scoped scan events stay in the editor and preserve pending
  inputs and conflict fingerprints. Only one camera panel is open. Scanner
  teardown runs on close, decoded delivery, row removal, and navigation.
- No schema, external credential, dependency, queue, or scheduled-work changes.

## Automated evidence

- Library unit: 41 focused recipe-library/bookmark/discovery/dashboard/onboarding
  tests passed, with 407 assertions.
- Barcode unit: 46 focused lookup/catalogue/matching/authoring tests passed,
  with 361 assertions. Provider requests use HTTP fakes.
- `npm run test:scanner`: 27 tests passed, including scoped event delivery,
  duplicate suppression, retry state and scanner teardown.
- `./vendor/bin/sail composer test`: 1,217 tests passed, 7,795 assertions.
- `./vendor/bin/sail pint --test`, `composer analyse`, `npm run build`,
  `npm run env:check`, and `npm run docs:check` passed through Sail.
  Example Compose configuration also passed `docker compose --env-file
  .env.example config --quiet`.
- The focused library/barcode browser run passed all three cases, with no
  axe violations. It covers active-page document titles, empty-code validation,
  explicit selection, row removal, navigation cleanup and both themes.
- The latest full `npm run test:accessibility` run passed all 21 checks,
  including shared secondary-bar dimensions and recipe quantity editing, with no
  axe violations. An earlier run caught fading deletion-dialog text during
  `deletion-cancelled`; this timing-sensitive finding did not recur.
- An earlier full run also exposed synthetic-fixture teardown errors. Teardown now
  removes this run's recipe matches and catalogue versions before catalogue
  identities, removes only its guarded synthetic users (including its final
  test administrator), and asserts that cleanup emits no error. Previous
  fixture cohorts from this task were cleaned from the testing database.
- The latest full `npm run test:planning` run passed all six checks.
- No schema, queue, deployment, dependency, baseline, or validator changes;
  migration/recovery drills and documentation-validator tests are not
  applicable. No remote CI result is claimed.

## Browser and release checks

The Selenium suite uses isolated synthetic recipes and an approved barcode,
never a live food provider. New journeys cover personal-list navigation,
continuing a draft, typed lookup, simulated decoded delivery, explicit
selection, preserved unsaved text, one active scanner and closed scanner
removal. Theme/mobile screenshots and axe reports are written to the existing
ignored `storage/app/testing/ux07` artifact directory. Mobile library and barcode
result screenshots were visually reviewed in light and dark themes. The
shared success-message component now uses readable green text in both themes;
the new light-theme scanner scan exposed its previous low-contrast color.

Physical-camera decoding, real permission prompts, camera switching on target
devices, and real screen-reader announcements are not established by simulated
browser scans or Node API doubles. These remain manual release checks; no
physical-device or assistive-technology pass is claimed.

## Secondary navigation refinement

The four recipe views now form a separate navigation bar directly below the
primary navigation. They reuse the primary navigation link component, including
its active underline, text, hover and focus treatments. Links use two columns
on narrow screens and a horizontal row when space permits.

The production build and focused recipe-library browser run passed. Keyboard
navigation, all four active states, axe, and 320px reflow were checked in both
themes; the resulting mobile screenshots were visually reviewed. Documentation checks
also passed. This presentation-only follow-up changes no PHP application code,
queries, authorization, schema, dependencies or configuration, so backend,
static-analysis, environment and migration checks were not repeated. The
full accessibility and planning suites were subsequently rerun successfully as
part of the shared-dimensions refinement below. Manual release checks remain.

## Shared secondary-bar dimensions

All authenticated page-heading bars and recipe tabs use one shared component
for the surface, container width, side padding and minimum height. Titles use
the same 14px size, 20px line height and medium weight as the navigation links.
Bars have a 48px content height on wider screens and 96px on narrow screens,
where the four recipe tabs occupy two rows; their shared border adds one pixel.
Long titles can wrap and grow rather than being clipped.

Rendered geometry checks compare Recipes, Dashboard, Meal plans and Food
catalogue at 1280px and 320px in both themes. Height, typography, padding and
alignment beneath primary navigation match across all four pages. Focused
browser checks, the production build, and visual screenshot review passed;
the full accessibility suite passed 20 checks and planning passed six.
The full backend regression gate also passed again: 1,204 tests and 7,715
assertions. Formatting, static analysis, scanner, environment, Compose and
documentation checks passed. No migration or dependency changes were needed.

## Recipe-editor quantity precision

REC-08 already supplies the ingredient quantity formatter: exact decimal
half-up rounding to at most three places, trailing-zero removal, and
`<0.001` for positive amounts below display resolution. DEC-003 and DEC-004
govern nutrient storage and nutrient-specific displays; those policies are
unchanged. The recipe editor previously bypassed the ingredient formatter and
loaded scale-18 database strings directly into its quantity fields.

The editor now uses that existing formatter. A locked, line-ID-keyed baseline
retains each exact amount separately from its display. Untouched quantities
use the original amount for validation and persistence, including after row
reordering, failed validation, finalization and revision publication. Actual
quantity-input events mark deliberate edits, including replacing a rounded
value with the same visible number. Explicit edits use the existing numeric
validation and storage boundary; missing and zero remain distinct. No migration
or stored-data rewrite is needed.

`RecipeQuantityEditorDisplayTest` covers whole/fractional amounts, half-up
ties, full precision, tiny positives, zero, missing values, large exact values,
intentional edits, clear/zero actions, validation recovery, ordering, stale
saves, immutable version snapshots and protected-baseline tampering. The
focused Chromium quantity journey passed and verifies visible field values,
save behavior and real input-event tracking in both themes, with no axe
violations. Final verification passed: 1,217 backend tests (7,795 assertions),
all 13 dedicated quantity regressions (80 assertions), 21 full accessibility
checks and six planning checks. Formatting, static analysis, the production
build, scanner, documentation and environment checks passed. Quantity-editor
screenshots were visually reviewed in both themes. The existing physical-camera
and assistive-technology release checks remain separate from this correction.
