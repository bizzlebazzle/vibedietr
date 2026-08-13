<?php

namespace Tests\Unit\Domain;

use App\Domain\Shared\ExactJsonDecoder;
use PHPUnit\Framework\TestCase;

class ExactJsonDecoderTest extends TestCase
{
    public function test_numeric_tokens_are_strings_without_changing_strings_or_structure(): void
    {
        $decoded = ExactJsonDecoder::decodeObject(
            '{"value":1.234567890123456789,"exponent":1e-18,"negative":-2,"text":"3.14","escaped":"quote: \\" 4"}',
        );

        $this->assertSame('1.234567890123456789', $decoded['value']);
        $this->assertSame('1e-18', $decoded['exponent']);
        $this->assertSame('-2', $decoded['negative']);
        $this->assertSame('3.14', $decoded['text']);
        $this->assertSame('quote: " 4', $decoded['escaped']);
    }
}
