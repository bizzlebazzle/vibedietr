<?php

namespace App\Domain\Shared;

use JsonException;

final class ExactJsonDecoder
{
    /**
     * Decode JSON objects while retaining every numeric token as its exact
     * base-10 source string.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function decodeObject(string $json): array
    {
        $quotedNumbers = '';
        $length = strlen($json);
        $insideString = false;
        $escaped = false;

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $json[$offset];

            if ($insideString) {
                $quotedNumbers .= $character;

                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $insideString = false;
                }

                continue;
            }

            if ($character === '"') {
                $insideString = true;
                $quotedNumbers .= $character;

                continue;
            }

            if (($character === '-' || ctype_digit($character))
                && preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/A', $json, $match, 0, $offset) === 1
            ) {
                $number = $match[0];
                $quotedNumbers .= json_encode($number, JSON_THROW_ON_ERROR);
                $offset += strlen($number) - 1;

                continue;
            }

            $quotedNumbers .= $character;
        }

        $decoded = json_decode($quotedNumbers, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('A JSON object is required.');
        }

        return $decoded;
    }
}
