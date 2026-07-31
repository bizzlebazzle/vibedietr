<?php

namespace Tests\Unit\Domain;

use App\Domain\Measurements\CustomUnit;
use App\Domain\Measurements\MeasurementUnitParser;
use App\Domain\Measurements\StandardUnit;
use PHPUnit\Framework\TestCase;

class MeasurementUnitParserTest extends TestCase
{
    public function test_standard_measurement_in_text_takes_precedence_over_count_wording(): void
    {
        $this->assertSame(StandardUnit::Gram, MeasurementUnitParser::findInText('1 biscuit (18 g)'));
    }

    public function test_existing_custom_measure_inference_remains_available(): void
    {
        $unit = MeasurementUnitParser::findInText('one handful');

        $this->assertInstanceOf(CustomUnit::class, $unit);
        $this->assertSame('handful', $unit->originalText);
    }
}
