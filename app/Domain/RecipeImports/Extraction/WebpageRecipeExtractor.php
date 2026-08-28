<?php

namespace App\Domain\RecipeImports\Extraction;

use App\Domain\RecipeImports\Parsing\ParsedRecipe;
use App\Domain\RecipeImports\Parsing\RecipeTextParser;
use App\Integrations\RecipeWebpages\WebpageFetchException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use JsonException;

final class WebpageRecipeExtractor
{
    public const IDENTIFIER = 'vibedietr.schema_jsonld_visible_text';

    public function __construct(private readonly RecipeTextParser $parser) {}

    public function extract(string $html): ExtractedWebpageRecipe
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new WebpageFetchException('malformed_html', 'extraction');
        }

        [$candidates, $malformed] = $this->candidates($document);
        $candidate = $this->select($candidates);
        $structured = null;
        if ($candidate !== null && ($source = $this->structuredSource($candidate)) !== null) {
            $parsed = $this->parse($source);
            if ($parsed !== null) {
                $warnings = $parsed->warnings;
                if (count($candidates) > 1) {
                    $warnings[] = 'multiple_recipe_candidates';
                }
                if ($malformed) {
                    $warnings[] = 'structured_data_malformed';
                }
                $structured = $this->result($parsed, $source, 'schema_jsonld', $warnings);
                if ($parsed->ingredients !== [] && $parsed->steps !== []) {
                    return $structured;
                }
            }
        }

        $source = $this->visibleText($document);
        $parsed = $this->parse($source);
        if ($parsed === null) {
            if ($structured !== null) {
                return $structured;
            }
            throw new WebpageFetchException('recipe_structure_not_found', 'extraction');
        }
        $warnings = $parsed->warnings;
        if ($malformed) {
            $warnings[] = 'structured_data_malformed';
        }
        if ($candidates !== []) {
            $warnings[] = 'extraction_incomplete';
        }

        return $this->result($parsed, $source, 'visible_text_fallback', $warnings);
    }

    private function parse(string $source): ?ParsedRecipe
    {
        if ($source === '') {
            return null;
        }
        try {
            return $this->parser->parse($source);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{list<array<string, mixed>>, bool} */
    private function candidates(DOMDocument $document): array
    {
        $scripts = (new DOMXPath($document))->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "application/ld+json")]');
        $recipes = [];
        $malformed = false;
        $blocks = 0;
        if ($scripts === false) {
            return [[], false];
        }

        foreach ($scripts as $script) {
            if (++$blocks > 50) {
                $malformed = true;
                break;
            }
            $json = trim((string) $script->textContent);
            if ($json === '' || strlen($json) > 262_144) {
                $malformed = true;

                continue;
            }
            try {
                $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
                $nodes = 0;
                $this->collect($decoded, $recipes, $nodes, 0);
            } catch (JsonException|WebpageFetchException) {
                $malformed = true;
            }
        }

        return [$recipes, $malformed];
    }

    /** @param array<int, array<string, mixed>> $recipes */
    private function collect(mixed $value, array &$recipes, int &$nodes, int $depth): void
    {
        if (++$nodes > 5_000 || $depth > 24) {
            throw new WebpageFetchException('structured_data_too_complex', 'limit');
        }
        if (! is_array($value)) {
            return;
        }
        $types = $value['@type'] ?? null;
        foreach (is_array($types) ? $types : [$types] as $type) {
            if (is_string($type) && strtolower($type) === 'recipe') {
                $recipe = [];
                foreach ($value as $key => $item) {
                    if (is_string($key)) {
                        $recipe[$key] = $item;
                    }
                }
                $recipes[] = $recipe;
                break;
            }
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collect($child, $recipes, $nodes, $depth + 1);
            }
        }
    }

    /** @param list<array<string, mixed>> $candidates @return array<string, mixed>|null */
    private function select(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn (array $a, array $b): int => $this->score($b) <=> $this->score($a));
        if (count($candidates) > 1) {
            $top = $this->score($candidates[0]);
            $next = $this->score($candidates[1]);
            if ($top < 6 || $top < $next * 2) {
                throw new WebpageFetchException('multiple_recipe_candidates', 'extraction');
            }
        }

        return $candidates[0];
    }

    /** @param array<string, mixed> $candidate */
    private function score(array $candidate): int
    {
        return (is_string($candidate['name'] ?? null) ? 2 : 0)
            + min(20, count($this->strings($candidate['recipeIngredient'] ?? null)))
            + min(20, count($this->instructions($candidate['recipeInstructions'] ?? null)));
    }

    /** @param array<string, mixed> $candidate */
    private function structuredSource(array $candidate): ?string
    {
        $ingredients = $this->strings($candidate['recipeIngredient'] ?? null);
        $instructions = $this->instructions($candidate['recipeInstructions'] ?? null);
        if ($ingredients === [] && $instructions === []) {
            return null;
        }

        $lines = [];
        if (($name = $this->string($candidate['name'] ?? null, 255)) !== null) {
            $lines[] = $name;
        }
        if (($yield = $this->string($candidate['recipeYield'] ?? null, 255)) !== null) {
            $lines[] = 'Yield: '.$yield;
        }
        if ($ingredients !== []) {
            $lines[] = 'Ingredients';
            array_push($lines, ...$ingredients);
        }
        if ($instructions !== []) {
            $lines[] = 'Instructions';
            array_push($lines, ...$instructions);
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (($text = $this->string($item, 10_000)) !== null) {
                $result[] = $text;
            }
            if (count($result) === 500) {
                break;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function instructions(mixed $value, int $depth = 0): array
    {
        if ($depth > 16) {
            return [];
        }
        if (is_string($value)) {
            return ($text = $this->string($value, 10_000)) === null ? [] : [$text];
        }
        if (! is_array($value)) {
            return [];
        }

        $types = is_array($value['@type'] ?? null) ? $value['@type'] : [$value['@type'] ?? null];
        $types = array_map(fn (mixed $type): string => is_string($type) ? strtolower($type) : '', $types);
        if (in_array('howtosection', $types, true)) {
            $lines = [];
            if (($name = $this->string($value['name'] ?? null, 255)) !== null) {
                $lines[] = $name.':';
            }

            return array_merge($lines, $this->instructions($value['itemListElement'] ?? [], $depth + 1));
        }
        if (in_array('howtostep', $types, true)) {
            $text = $this->string($value['text'] ?? $value['name'] ?? null, 10_000);

            return $text === null ? [] : [$text];
        }

        $lines = [];
        foreach ($value as $child) {
            array_push($lines, ...$this->instructions($child, $depth + 1));
            if (count($lines) >= 500) {
                break;
            }
        }

        return $lines;
    }

    private function string(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    private function visibleText(DOMDocument $document): string
    {
        $xpath = new DOMXPath($document);
        $remove = $xpath->query('//script|//style|//noscript|//template|//svg|//canvas|//nav|//footer|//form|//*[@hidden]|//*[@aria-hidden="true"]');
        if ($remove !== false) {
            foreach (iterator_to_array($remove) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        if (! $root instanceof DOMNode) {
            return '';
        }
        $count = 0;
        $lines = preg_split('/\R/u', $this->nodeText($root, $count, 0)) ?: [];
        $lines = array_values(array_filter(array_map(
            fn (string $line): string => trim(preg_replace('/[\t ]+/u', ' ', $line) ?? $line),
            $lines,
        ), fn (string $line): bool => $line !== ''));

        return mb_substr(implode("\n", $lines), 0, 500_000);
    }

    private function nodeText(DOMNode $node, int &$count, int $depth): string
    {
        if (++$count > 10_000 || $depth > 64) {
            return '';
        }
        if ($node->nodeType === XML_TEXT_NODE) {
            return (string) $node->nodeValue;
        }
        $block = $node instanceof DOMElement && in_array(strtolower($node->tagName), [
            'article', 'blockquote', 'br', 'div', 'h1', 'h2', 'h3', 'h4',
            'h5', 'h6', 'li', 'main', 'p', 'section', 'table', 'tr',
        ], true);
        $text = $block ? "\n" : '';
        foreach ($node->childNodes as $child) {
            $text .= $this->nodeText($child, $count, $depth + 1);
        }

        return $text.($block ? "\n" : '');
    }

    /** @param list<string> $warnings */
    private function result(ParsedRecipe $parsed, string $source, string $method, array $warnings): ExtractedWebpageRecipe
    {
        $warnings = array_values(array_unique($warnings));
        $strong = $parsed->ingredients === [] || $parsed->steps === []
            || array_intersect($warnings, ['structured_data_malformed', 'multiple_recipe_candidates', 'extraction_incomplete']) !== [];
        $recipe = new ParsedRecipe(
            $parsed->title, $parsed->servings, $parsed->ingredients, $parsed->sections,
            $parsed->steps, $warnings, $parsed->parserIdentifier, $parsed->parserVersion,
            $strong ? 'reviewable_with_strong_warnings' : 'reviewable',
        );

        return new ExtractedWebpageRecipe(
            $recipe, $source, $method, self::IDENTIFIER,
            (string) (config('production.imports.extractor_version') ?: 'rec16-v1'),
        );
    }
}
