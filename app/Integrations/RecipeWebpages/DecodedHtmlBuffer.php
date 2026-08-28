<?php

namespace App\Integrations\RecipeWebpages;

final class DecodedHtmlBuffer
{
    /** @var array<string, string> */
    public array $headers = [];

    public string $body = '';

    public bool $oversized = false;

    public function __construct(private readonly int $maximumBytes) {}

    public function header(string $line): int
    {
        $length = strlen($line);
        $position = strpos($line, ':');
        if ($position === false) {
            return $length;
        }
        $name = strtolower(trim(substr($line, 0, $position)));
        $value = trim(substr($line, $position + 1));
        $this->headers[$name] = $value;
        if ($name === 'content-length' && ctype_digit($value) && (int) $value > $this->maximumBytes) {
            $this->oversized = true;

            return 0;
        }

        return $length;
    }

    public function append(string $chunk): int
    {
        if (strlen($this->body) + strlen($chunk) > $this->maximumBytes) {
            $this->oversized = true;

            return 0;
        }
        $this->body .= $chunk;

        return strlen($chunk);
    }
}
