<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\Parsing\DeterministicRecipeTextParser;
use App\Domain\RecipeImports\Parsing\NoCredibleRecipeStructure;
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
}
