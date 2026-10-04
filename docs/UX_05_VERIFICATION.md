# UX-05 planning interface verification

UX-05 is complete as confirmed by the product owner on 2026-10-04 and
remains P2. PLAN-05, PLAN-12, and UX-02 are complete; DEC-019 is decided.
The interface uses the established owner-only controller/domain boundaries.
Calculation, target classification, pinned snapshots, diary dates, timezone
resolution, correction history, and sharing rules remain unchanged.

## Implementation and automated coverage

- Recipe, catalogue, and private one-off entries move through native labelled
  day/slot selectors and submit buttons. No drag gesture is required. Slot
  ordering normalizes validated HTML string IDs before the existing domain
  checks for ownership and a complete, unique slot list.
- Entry state says planned/not consumed, consumed, or consumption reversed.
  Actual quantities, local time, timezone, offset, and diary date remain
  distinct from planned amounts. Entries with history explain unavailable
  movement/removal. Reusable entries explain consumption ineligibility.
- Native forms expose consume, correct, reverse, and re-consume. First intake
  defaults to planned quantity; re-consumption requests explicit actual amount.
  Native date/time input is normalized to the existing wall-clock parser.
  Scoped consumption errors retain input, reopen the disclosure, associate
  errors, and use UX-02's focused summary. Success returns focus to entry state.
- Daily nutrient comparisons use named nutrient sections and definition lists
  with persistent target, planned, and consumed labels. Existing estimate,
  partial, unavailable, zero, classification, and non-medical wording is kept.
  Controls and dense forms wrap, with visible focus and usable touch targets.
- `PlanningInterfaceTest.php` covers native date/time consumption, corrections,
  reversal/re-consumption, unchanged planned servings, scoped validation input,
  and reusable ineligibility. `MealPlanDaySlotTest.php` covers HTML string IDs.
  Existing planning suites cover authorization, privacy, nutrition, history,
  and ownership at the unchanged domain boundaries.
- `planning.test.mjs` under `tests/Frontend` covers outcome focus and validation
  precedence with DOM doubles. These are not browser assertions.
- `tests/Browser/planning.test.mjs` uses Node's test runner, the existing Sail
  Selenium Chromium service, WebDriver, and axe. It covers keyboard movement of
  all entry kinds, native forms with application JavaScript disabled, touch
  movement/consumption, correction/reversal/re-consumption, validation focus,
  associated errors, accessible names and Chromium accessibility-tree state,
  slot naming/order, future-phase edits, target-profile edits preserving
  historical target values, zero/missing/partial/estimate distinctions, and axe
  scans in light/dark themes. Browser zoom is set to 200% and its actual device
  pixel ratio is asserted; all expanded planning forms and nutrient sections
  are checked for viewport overflow. Mobile widths include 390 and 320 CSS px.

Run the suite with `./vendor/bin/sail npm run test:planning` after a frontend
build, separately from PHPUnit. The synthetic fixtures use only the testing
MySQL database, additive migrations, and cleanup of their own user. The guarded
test server is independent of the normal application server and uses built
assets without changing Vite's development configuration.

Screenshots under `storage/app/testing/ux05` cover mobile light/dark, narrow
expanded forms, desktop, and actual 200% planning/target views. They are review
artifacts rather than pixel-regression baselines. CI uploads them and runs the
suite within the existing `Backend tests` gate. Axe scans the affected planning
content, not unrelated global navigation or other application areas.

## Remaining external and manual checks

Remote `Backend tests`, `PHP formatting`, `Static analysis`, and `Frontend build`
statuses remain required before merge; this task does not push or publish a PR.
Automated accessibility-tree assertions do not emulate a real screen reader.
Before release, check the following with real assistive technology and devices:

1. With a screen reader, navigate nutrient definition lists and planned versus
   actual states; verify correction/reversal outcomes, labels, validation
   announcements, and entry focus in the supported browser/screen-reader pair.
2. On physical touch devices, use native date/time and select pickers, including
   an explicit timezone and a repeated daylight-saving time's intended offset.
3. Review light/dark screenshots and real device text at mobile/desktop sizes
   and 200% zoom. Automated bounds and axe cannot establish every visual or
   assistive-technology behavior.
