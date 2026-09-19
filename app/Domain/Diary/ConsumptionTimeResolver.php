<?php

namespace App\Domain\Diary;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

final class ConsumptionTimeResolver
{
    public function resolve(?string $localValue, string $timezone, ?int $selectedOffsetMinutes, bool $allowNowDefault): ResolvedConsumptionTime
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true) && $timezone !== 'UTC') {
            throw ValidationException::withMessages(['timezone' => 'Select a valid IANA timezone.']);
        }
        if ($localValue === null || trim($localValue) === '') {
            if (! $allowNowDefault) {
                throw ValidationException::withMessages(['consumed_local_at' => 'Enter the consumption time for another diary date.']);
            }
            $utc = Date::now()->utc()->toImmutable();
            $local = $utc->setTimezone($timezone);

            return new ResolvedConsumptionTime($local, $timezone, $local->offsetMinutes, $utc);
        }

        $value = trim($localValue);
        $parsed = null;
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $candidate = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($candidate !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $parsed = $candidate;
                break;
            }
        }
        if (! $parsed) {
            throw ValidationException::withMessages(['consumed_local_at' => 'Enter a valid local date and time.']);
        }

        $zone = new DateTimeZone($timezone);
        $localTimestamp = $parsed->getTimestamp();
        $transitions = $zone->getTransitions($localTimestamp - 172800, $localTimestamp + 172800) ?: [];
        $offsets = array_values(array_unique(array_map(fn (array $t): int => (int) $t['offset'], $transitions)));
        $matches = [];
        foreach ($offsets as $offset) {
            $instant = (new DateTimeImmutable('@'.($localTimestamp - $offset)))->setTimezone($zone);
            if ($instant->format('Y-m-d H:i:s') === $parsed->format('Y-m-d H:i:s')) {
                $matches[(int) ($offset / 60)] = $instant;
            }
        }
        if ($matches === []) {
            throw ValidationException::withMessages(['consumed_local_at' => 'That local time does not exist in the selected timezone.']);
        }
        if (count($matches) > 1 && $selectedOffsetMinutes === null) {
            throw ValidationException::withMessages(['utc_offset_minutes' => 'Select which UTC offset applies to this repeated local time.']);
        }
        $offsetMinutes = $selectedOffsetMinutes ?? (int) array_key_first($matches);
        if (! isset($matches[$offsetMinutes])) {
            throw ValidationException::withMessages(['utc_offset_minutes' => 'The selected UTC offset does not apply to this local time.']);
        }
        $utc = CarbonImmutable::instance($matches[$offsetMinutes])->utc();
        if ($utc->isAfter(Date::now()->utc())) {
            throw ValidationException::withMessages(['consumed_local_at' => 'Consumption time cannot be in the future.']);
        }

        return new ResolvedConsumptionTime(
            CarbonImmutable::instance($matches[$offsetMinutes]),
            $timezone,
            $offsetMinutes,
            $utc,
        );
    }
}
