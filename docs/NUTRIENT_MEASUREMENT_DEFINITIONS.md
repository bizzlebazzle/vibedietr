# Nutrient and measurement definitions

## Purpose

Application-owned nutrient and measurement definitions live under
`app/Domain/Nutrition` and `app/Domain/Measurements`. Components, controllers,
imports, and validation should consume these APIs rather than create local
identifier, unit, alias, precision, or conversion tables.

## Extending the nutrient catalogue

1. Add a stable machine identifier to `Nutrient`.
2. Add its complete metadata to `NutrientRegistry`, including canonical and
   display units, supported bases, display precision, derivation, and only the
   aliases that are unambiguous.
3. Add or update focused completeness, metadata, conversion, and display tests.

Storage precision, working precision, storage quantization, energy authority,
and source/display separation come from DEC-003. Display units, decimal places,
round-half-up behavior, and zero/trace/missing presentation come from DEC-004.
Do not create component-specific alternatives to those rules.

## Extending standard measurements

Add a stable `StandardUnit` identifier and one `StandardUnitDefinition` with a
symbol, label, dimension, canonical factor, and aliases. Mass factors are exact
grams and volume factors are exact millilitres. Only add an alias when all
accepted spellings have one product meaning; ambiguous input must remain a
custom unit.

Count labels have no universal factor. They support identity conversion only:
for example, cloves can remain cloves, but cloves cannot become pieces, grams,
or millilitres. Add a new same-dimension standard unit only with an approved,
exact canonical factor and conversion tests in both directions.

## Custom units

`MeasurementUnitRegistry::normalize()` returns a `CustomUnit` when input is not
an unambiguous standard alias. The object retains the user's exact text and has
the custom, non-convertible dimension. Safe custom units of at most 32
characters remain valid for storage, display, editing, and proportional recipe
resizing. An explicit future mapping is required before any custom-unit
conversion.

## Conversion boundary

`UnitConverter` converts only compatible standard mass or volume units using
`Brick\Math\BigDecimal`. It does not use binary floating point, does not round
to storage scale between operations, and retains DEC-003's division guard
scale. Count identity is permitted; other count conversion and every custom or
cross-dimension request returns a domain error.

Mass-to-volume and volume-to-mass conversion are intentionally prohibited.
Those conversions depend on the particular food's density, packing, shape, or
edible portion and belong only in a future ingredient-specific service with
reliable sourced data.

## Current migration status

The ingredient Livewire form now sources its unit choices, validation,
normalization, custom-unit handling, and OpenFoodFacts unit inference from the
shared measurement layer. Conventional ingredient request validation and the
audit-event nutrient allowlist also use shared definitions.

The ingredient form and show component still contain their legacy editable
nutrition-field maps and display-row layout. Their destructive numeric
normalization and the duplicated measurement display formatting in the list
and show components remain assigned to STB-05/STB-06 and a narrow presentation
follow-up; FND-06 does not rewrite those unrelated paths.
