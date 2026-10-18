<?php

namespace App\Domain\AccountExports;

use Brick\Math\BigDecimal;

/** Keep numeric JSON tokens lexical until the schema identifies integer versus decimal. */
final class StoredJson
{
    public static function decode(string $json): mixed
    {
        $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/s', static function (array $match): string {
            return str_starts_with($match[0], '"') ? $match[0] : json_encode((string) BigDecimal::of($match[0]), JSON_THROW_ON_ERROR);
        }, $json);

        return json_decode($quoted, true, flags: JSON_THROW_ON_ERROR);
    }
}
