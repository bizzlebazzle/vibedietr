<?php

namespace App\Domain\RecipeImports\Extraction;

use App\Queue\Exceptions\NonRetryableJobException;
use App\Security\Parsing\ParsingBudget;
use App\Security\Parsing\ResourceGuard;
use DOMDocument;
use DOMNode;

final class DocumentTextExtractor
{
    public const IDENTIFIER = 'vibedietr.uploaded_document';

    public function __construct(private readonly ResourceGuard $guard) {}

    public function extract(string $bytes, string $extension): string
    {
        $budget = new ParsingBudget(
            maxBytes: 2_097_152,
            maxChars: 2_000_000,
            maxItems: 10_000,
            maxDepth: 32,
            maxMilliseconds: 5_000,
        );
        $this->guard->assertInput($bytes, $budget);
        $text = $this->utf8($bytes);
        if ($this->binary($text)) {
            throw new NonRetryableJobException('binary_text_content');
        }

        if ($extension === 'html') {
            $text = $this->html($text, $budget);
        }

        $this->guard->assertInput($text, $budget);

        return $text;
    }

    private function utf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        $encoding = match (true) {
            str_starts_with($bytes, "\xFF\xFE") => 'UTF-16LE',
            str_starts_with($bytes, "\xFE\xFF") => 'UTF-16BE',
            default => null,
        };
        if ($encoding === null) {
            throw new NonRetryableJobException('unsupported_text_encoding');
        }
        $converted = mb_convert_encoding(substr($bytes, 2), 'UTF-8', $encoding);
        if (! mb_check_encoding($converted, 'UTF-8')) {
            throw new NonRetryableJobException('unsupported_text_encoding');
        }

        return $converted;
    }

    private function binary(string $text): bool
    {
        if (str_contains($text, "\0")) {
            return true;
        }
        $sample = substr($text, 0, 65_536);
        $controls = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $sample);

        return is_int($controls) && $controls > max(8, (int) (strlen($sample) * 0.01));
    }

    private function html(string $html, ParsingBudget $budget): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $html = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $html) ?? $html;
            $html = preg_replace('/<\/(?:p|div|li|h[1-6]|section|article)>/iu', "\n", $html) ?? $html;
            $dom = new DOMDocument;
            if (! $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                throw new NonRetryableJobException('malformed_html');
            }
            foreach (['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object'] as $tag) {
                $nodes = $dom->getElementsByTagName($tag);
                while ($nodes->length > 0) {
                    $nodes->item(0)?->parentNode?->removeChild($nodes->item(0));
                }
            }
            $count = 0;
            $stack = [[$dom->documentElement, 1]];
            while ($stack !== []) {
                [$node, $depth] = array_pop($stack);
                if (! $node instanceof DOMNode) {
                    continue;
                }
                $count++;
                $this->guard->assertItems($count, $budget);
                $this->guard->assertDepth($depth, $budget);
                foreach ($node->childNodes as $child) {
                    $stack[] = [$child, $depth + 1];
                }
            }

            $body = $dom->getElementsByTagName('body')->item(0);
            $text = (string) ($body instanceof DOMNode ? $body->textContent : $dom->textContent);
            $lines = preg_split('/\R/u', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: [];

            return implode("\n", array_values(array_filter(array_map(
                static fn (string $line): string => trim(preg_replace('/[\t ]+/u', ' ', $line) ?? $line),
                $lines,
            ), static fn (string $line): bool => $line !== '')));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
