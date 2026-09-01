<?php

namespace App\Domain\Catalogue;

use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Measurements\UnitConverter;
use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class PackageStructure
{
    private function __construct(
        public ?int $packageCount,
        public ?string $itemType,
        public ?BigDecimal $amountPerItem,
        public ?StandardUnit $amountPerItemUnit,
        public ?BigDecimal $servingsPerItem,
        public ?BigDecimal $servingAmount,
        public ?StandardUnit $servingAmountUnit,
        public ?ServingAmountBasis $servingAmountBasis,
    ) {}

    public static function make(
        int|string|null $packageCount = null,
        ?string $itemType = null,
        string|int|null $amountPerItem = null,
        StandardUnit|string|null $amountPerItemUnit = null,
        string|int|null $servingsPerItem = null,
        string|int|null $servingAmount = null,
        StandardUnit|string|null $servingAmountUnit = null,
    ): self {
        $packageCount = self::positiveInteger($packageCount, 'Package count');
        $itemType = self::itemType($itemType);
        $amountPerItem = self::positiveDecimal($amountPerItem, 'Amount per item');
        $amountPerItemUnit = self::unit($amountPerItemUnit);
        self::assertPair($amountPerItem, $amountPerItemUnit, 'Amount per item');
        $servingsPerItem = self::positiveDecimal($servingsPerItem, 'Servings per item');
        $servingAmount = self::positiveDecimal($servingAmount, 'Serving amount');
        $servingAmountUnit = self::unit($servingAmountUnit);
        self::assertPair($servingAmount, $servingAmountUnit, 'Serving amount');

        if ($servingAmount !== null) {
            return new self(
                $packageCount,
                $itemType,
                $amountPerItem,
                $amountPerItemUnit,
                $servingsPerItem,
                $servingAmount,
                $servingAmountUnit,
                ServingAmountBasis::Source,
            );
        }

        if ($amountPerItem !== null && $servingsPerItem !== null) {
            return new self(
                $packageCount,
                $itemType,
                $amountPerItem,
                $amountPerItemUnit,
                $servingsPerItem,
                self::deriveServingAmount($amountPerItem, $servingsPerItem),
                $amountPerItemUnit,
                ServingAmountBasis::AmountPerItemDividedByServingsPerItem,
            );
        }

        return new self(
            $packageCount,
            $itemType,
            $amountPerItem,
            $amountPerItemUnit,
            $servingsPerItem,
            null,
            null,
            null,
        );
    }

    public static function fromPersisted(
        int|string|null $packageCount,
        ?string $itemType,
        string|int|null $amountPerItem,
        StandardUnit|string|null $amountPerItemUnit,
        string|int|null $servingsPerItem,
        string|int|null $servingAmount,
        StandardUnit|string|null $servingAmountUnit,
        ServingAmountBasis|string|null $servingAmountBasis,
    ): self {
        $candidate = self::make(
            packageCount: $packageCount,
            itemType: $itemType,
            amountPerItem: $amountPerItem,
            amountPerItemUnit: $amountPerItemUnit,
            servingsPerItem: $servingsPerItem,
            servingAmount: $servingAmount,
            servingAmountUnit: $servingAmountUnit,
        );
        $basis = is_string($servingAmountBasis)
            ? ServingAmountBasis::tryFrom($servingAmountBasis)
            : $servingAmountBasis;

        if ($servingAmountBasis !== null && $basis === null) {
            throw new InvalidArgumentException('Serving amount basis is not supported.');
        }

        if ($candidate->servingAmount === null && $basis !== null) {
            throw new InvalidArgumentException('Serving amount basis requires a serving amount and unit.');
        }

        if ($candidate->servingAmount !== null && $basis === null) {
            throw new InvalidArgumentException('Serving amount and unit require a derivation basis.');
        }

        if ($basis === ServingAmountBasis::AmountPerItemDividedByServingsPerItem) {
            if ($candidate->amountPerItem === null
                || $candidate->amountPerItemUnit === null
                || $candidate->servingsPerItem === null
                || $candidate->servingAmountUnit !== $candidate->amountPerItemUnit
            ) {
                throw new InvalidArgumentException('A derived serving amount requires the amount-per-item pair, servings per item, and the same unit.');
            }

            $expected = self::deriveServingAmount($candidate->amountPerItem, $candidate->servingsPerItem);

            if (! $expected->isEqualTo($candidate->servingAmount)) {
                throw new InvalidArgumentException('The persisted derived serving amount is stale or inconsistent.');
            }
        }

        return new self(
            $candidate->packageCount,
            $candidate->itemType,
            $candidate->amountPerItem,
            $candidate->amountPerItemUnit,
            $candidate->servingsPerItem,
            $candidate->servingAmount,
            $candidate->servingAmountUnit,
            $basis,
        );
    }

    /** @return array<string, int|string|null> */
    public function toAttributes(): array
    {
        return [
            'package_count' => $this->packageCount,
            'item_type' => $this->itemType,
            'amount_per_item' => self::stored($this->amountPerItem),
            'amount_per_item_unit' => $this->amountPerItemUnit?->value,
            'servings_per_item' => self::stored($this->servingsPerItem),
            'serving_amount' => self::stored($this->servingAmount),
            'serving_amount_unit' => $this->servingAmountUnit?->value,
            'serving_amount_basis' => $this->servingAmountBasis?->value,
        ];
    }

    public function amountPerItemIn(StandardUnit $unit): ?BigDecimal
    {
        if ($this->amountPerItem === null || $this->amountPerItemUnit === null) {
            return null;
        }

        return (new UnitConverter)->convert((string) $this->amountPerItem, $this->amountPerItemUnit, $unit);
    }

    public function servingAmountIn(StandardUnit $unit): ?BigDecimal
    {
        if ($this->servingAmount === null || $this->servingAmountUnit === null) {
            return null;
        }

        return (new UnitConverter)->convert((string) $this->servingAmount, $this->servingAmountUnit, $unit);
    }

    private static function positiveInteger(int|string|null $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        if ((is_string($value) && preg_match('/^[1-9]\d*$/', $value) !== 1)
            || (is_int($value) && $value <= 0)
        ) {
            throw new InvalidArgumentException("{$label} must be a positive integer or null.");
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($normalized === false) {
            throw new InvalidArgumentException("{$label} must be a positive integer or null.");
        }

        return $normalized;
    }

    private static function positiveDecimal(string|int|null $value, string $label): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }

        try {
            $decimal = Decimal::parse($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException("{$label} must be a positive decimal or null.", previous: $exception);
        }

        if (! $decimal->isPositive()) {
            throw new InvalidArgumentException("{$label} must be greater than zero when present.");
        }

        Decimal::forStorage($decimal);

        return $decimal;
    }

    private static function unit(StandardUnit|string|null $unit): ?StandardUnit
    {
        if ($unit === null) {
            return null;
        }

        if ($unit instanceof StandardUnit) {
            return $unit;
        }

        $standard = StandardUnit::tryFrom($unit) ?? MeasurementUnitRegistry::findStandard($unit);

        if ($standard === null) {
            throw new InvalidArgumentException('Package and serving amounts require a standard FND-06 measurement unit.');
        }

        return $standard;
    }

    private static function itemType(?string $itemType): ?string
    {
        if ($itemType === null || trim($itemType) === '') {
            return null;
        }

        $itemType = trim($itemType);

        if (mb_strlen($itemType) > 32 || preg_match('/[\x00-\x1F\x7F]/u', $itemType)) {
            throw new InvalidArgumentException('Item type must be safe text of at most 32 characters.');
        }

        return $itemType;
    }

    private static function assertPair(mixed $amount, mixed $unit, string $label): void
    {
        if (($amount === null) !== ($unit === null)) {
            throw new InvalidArgumentException("{$label} and unit must either both be present or both be null.");
        }
    }

    private static function deriveServingAmount(BigDecimal $amountPerItem, BigDecimal $servingsPerItem): BigDecimal
    {
        return BigDecimal::of(Decimal::forStorage($amountPerItem->dividedBy(
            $servingsPerItem,
            Decimal::DIVISION_GUARD_SCALE,
            Decimal::ROUNDING_MODE,
        )));
    }

    private static function stored(?BigDecimal $value): ?string
    {
        return $value === null ? null : Decimal::forStorage($value);
    }
}
