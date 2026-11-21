<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\Extraction\DocumentTextExtractor;
use App\Domain\RecipeImports\Parsing\DeterministicRecipeTextParser;
use App\Domain\RecipeImports\Parsing\NoCredibleRecipeStructure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecipeImportParserTest extends TestCase
{
    public function test_standard_fixture_preserves_exact_wording_unicode_whitespace_and_punctuation(): void
    {
        $result = app(DeterministicRecipeTextParser::class)->parse($this->fixture('standard.txt'));

        $this->assertSame('Synthetic Lemon Loaf', $result->title);
        $this->assertSame('8', $result->servings);
        $this->assertSame([
            '1  1/2 cups   plain flour, sifted',
            '½ tsp salt',
            'salt to taste',
        ], array_map(fn ($line): string => $line->originalText, $result->ingredients));
        $this->assertSame([
            '1.  Mix gently — don’t overwork.',
            '2. Bake at 180°C; cool.',
        ], array_map(fn ($step): string => $step->text, $result->steps));
        $this->assertSame('1.500000000000000000', $result->ingredients[0]->quantity);
        $this->assertSame('0.500000000000000000', $result->ingredients[1]->quantity);
        $this->assertContains('ingredient_quantity_uncertain', $result->ingredients[2]->warnings);
    }

    public function test_sectioned_fixture_groups_steps_and_keeps_unknown_unit_reviewable(): void
    {
        $result = app(DeterministicRecipeTextParser::class)->parse($this->fixture('sectioned.txt'));

        $this->assertSame(['Prepare', 'Finish'], array_map(fn ($section): string => $section->name, $result->sections));
        $this->assertSame(['section-0', 'section-1'], array_map(fn ($step): ?string => $step->sectionKey, $result->steps));
        $this->assertSame('custommeasure', $result->ingredients[1]->unit);
        $this->assertTrue($result->ingredients[1]->requiresReview());
        $this->assertContains('ingredient_unit_uncertain', $result->ingredients[1]->warnings);
    }

    public function test_free_form_and_ambiguous_inputs_are_reviewable_without_fake_confidence(): void
    {
        $freeForm = app(DeterministicRecipeTextParser::class)->parse($this->fixture('free-form.txt'));
        $ambiguous = app(DeterministicRecipeTextParser::class)->parse($this->fixture('ambiguous.txt'));

        $this->assertSame('reviewable_with_strong_warnings', $freeForm->completionClassification);
        $this->assertContains('extraction_incomplete', $freeForm->warnings);
        $this->assertSame('salt to taste', $ambiguous->ingredients[0]->originalText);
        $this->assertTrue($ambiguous->ingredients[0]->requiresReview());
        $this->assertObjectNotHasProperty('confidence', $ambiguous);
    }

    public function test_malformed_input_without_credible_structure_is_rejected_safely(): void
    {
        $this->expectException(NoCredibleRecipeStructure::class);

        app(DeterministicRecipeTextParser::class)->parse($this->fixture('malformed.txt'));
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/RecipeImports/'.$name)) ?: '';
    }

    /** @return iterable<string, array{string}> */
    public static function encodedTextFormats(): iterable
    {
        yield 'plain text' => ['text'];
        yield 'Markdown' => ['markdown'];
        yield 'HTML' => ['html'];
    }

    #[DataProvider('encodedTextFormats')]
    public function test_all_text_formats_decode_named_decimal_and_hex_entities_in_recipe_fields(string $format): void
    {
        $source = "Terry&#39;s &amp; orange cake\nServes: 2\nIngredients\n  300 g Terry&#39;s chocolate orange  \n&#189; tsp salt\nInstructions\nFinish &amp; serve:\n1. Mix &quot;gently&quot; &#x2014; don&apos;t overwork &unknown;.";
        if ($format === 'markdown') {
            $source = '# '.str_replace(['Ingredients', 'Instructions'], ['## Ingredients', '## Instructions'], $source);
        } elseif ($format === 'html') {
            $source = '<div>'.str_replace("\n", '<br>', $source).'</div>';
        }

        config(['production.imports.parser_version' => null]);
        $result = app(DeterministicRecipeTextParser::class)->parse($source);

        $this->assertSame("Terry's & orange cake", $result->title);
        $this->assertSame("  300 g Terry's chocolate orange  ", $result->ingredients[0]->originalText);
        $this->assertSame("Terry's chocolate orange", $result->ingredients[0]->genericWording);
        $this->assertSame('½ tsp salt', $result->ingredients[1]->originalText);
        $this->assertSame('0.500000000000000000', $result->ingredients[1]->quantity);
        $this->assertSame('Finish & serve', $result->sections[0]->name);
        $this->assertSame('1. Mix "gently" — don\'t overwork &unknown;.', $result->steps[0]->text);
        $this->assertSame('rec15-v2', $result->parserVersion);
    }

    public function test_decoded_markup_like_characters_are_not_reparsed_or_recursively_decoded(): void
    {
        $source = "Symbols\nIngredients\n300 g chocolate &lt;img src=x onerror=alert(1)&gt; &amp;#39;\nInstructions\nMix until 1 &lt; 2 &amp; 3 &gt; 2.";
        $result = app(DeterministicRecipeTextParser::class)->parse($source);

        $this->assertSame('300 g chocolate <img src=x onerror=alert(1)> &#39;', $result->ingredients[0]->originalText);
        $this->assertSame('Mix until 1 < 2 & 3 > 2.', $result->steps[0]->text);
    }

    public function test_uploaded_document_text_uses_the_same_entity_decoding(): void
    {
        foreach (['txt', 'md', 'html'] as $extension) {
            $source = "Chocolate\nIngredients\n300 g Terry&#39;s chocolate orange\nInstructions\nMix &amp; serve.";
            if ($extension === 'html') {
                $source = '<html><body><p>'.str_replace("\n", '</p><p>', $source).'</p></body></html>';
            }
            $extracted = app(DocumentTextExtractor::class)->extract($source, $extension);
            $result = app(DeterministicRecipeTextParser::class)->parse($extracted);

            $this->assertSame("300 g Terry's chocolate orange", $result->ingredients[0]->originalText);
            $this->assertSame('Mix & serve.', $result->steps[0]->text);
        }
    }
}
