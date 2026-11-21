# UX-08 recipe navigation and barcode matching

Implemented on branch `recipe-ux-fixes`; **incomplete** at the required full
accessibility gate. The recipe-specific checks pass. The full run found an
out-of-scope contrast failure while the existing account-deletion dialog was
fading out. Work stopped under the repository's unrelated-test-failure rule;
that flow was not changed.

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
- `./vendor/bin/sail composer test`: 1,204 tests passed, 7,715 assertions.
- `./vendor/bin/sail pint --test`, `composer analyse`, `npm run build`,
  `npm run env:check`, and `npm run docs:check` passed through Sail.
  Example Compose configuration also passed `docker compose --env-file
  .env.example config --quiet`.
- The focused library/barcode browser run passed all three cases, with no
  axe violations. It covers active-page document titles, empty-code validation,
  explicit selection, row removal, navigation cleanup and both themes.
- The latest full `npm run test:accessibility` run passed its 18 product
  journeys, including both new recipe journeys. Its final axe aggregation
  failed on `deletion-cancelled`: fading deletion-dialog text was still visible
  during the scan. This timing-sensitive finding is outside recipe UX; the
  earlier full attempt passed that existing journey.
- The full run also exposed synthetic-fixture teardown errors. Teardown now
  removes this run's recipe matches and catalogue versions before catalogue
  identities, removes only its guarded synthetic users (including its final
  test administrator), and asserts that cleanup emits no error. Previous
  fixture cohorts from this task were cleaned from the testing database.
- `npm run test:planning` was not run: the sequential browser command stopped
  at the accessibility failure, and the repository stop rule applies. Rerun
  full accessibility and planning after resolving that unrelated blocker.
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
existing full-accessibility blocker and outstanding release checks above remain;
the full accessibility and planning suites were not rerun for this refinement.
