<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\Extraction\WebpageRecipeExtractor;
use App\Integrations\RecipeWebpages\WebpageFetchException;
use Tests\TestCase;

class WebpageRecipeExtractorTest extends TestCase
{
    public function test_complete_json_ld_preserves_ingredient_step_and_section_wording(): void
    {
        $result = app(WebpageRecipeExtractor::class)->extract($this->fixture('complete-jsonld.html'));

        $this->assertSame('schema_jsonld', $result->method);
        $this->assertSame('Synthetic Lemon Cake', $result->recipe->title);
        $this->assertSame(['1  1/2 cups   flour', '½ tsp salt'], collect($result->recipe->ingredients)->pluck('originalText')->all());
        $this->assertSame(['Mix gently — do not overwork.', 'Bake at 180°C.'], collect($result->recipe->steps)->pluck('text')->all());
        $this->assertSame(['Prepare'], collect($result->recipe->sections)->pluck('name')->all());
    }

    public function test_graph_recipe_and_string_instruction_are_supported(): void
    {
        $result = app(WebpageRecipeExtractor::class)->extract($this->fixture('graph-jsonld.html'));

        $this->assertSame('Graph Soup', $result->recipe->title);
        $this->assertSame('Simmer gently.', $result->recipe->steps[0]->text);
    }

    public function test_malformed_json_ld_falls_back_to_visible_text_and_removes_scripts_styles(): void
    {
        $result = app(WebpageRecipeExtractor::class)->extract($this->fixture('fallback.html'));

        $this->assertSame('visible_text_fallback', $result->method);
        $this->assertContains('structured_data_malformed', $result->recipe->warnings);
        $this->assertSame('reviewable_with_strong_warnings', $result->recipe->completionClassification);
        $this->assertStringNotContainsString('privateScriptToken', $result->sourceText);
        $this->assertStringNotContainsString('.secret', $result->sourceText);
    }

    public function test_non_recipe_html_fails_without_a_result(): void
    {
        try {
            app(WebpageRecipeExtractor::class)->extract($this->fixture('no-recipe.html'));
            $this->fail('Non-recipe HTML should fail.');
        } catch (WebpageFetchException $exception) {
            $this->assertSame('recipe_structure_not_found', $exception->safeErrorCode);
        }
    }

    public function test_excessively_deep_json_ld_is_bounded_and_fails_safely(): void
    {
        $recipe = [
            '@type' => 'Recipe',
            'name' => 'Too Deep',
            'recipeIngredient' => ['1 cup flour'],
            'recipeInstructions' => ['Mix.'],
        ];
        for ($depth = 0; $depth < 30; $depth++) {
            $recipe = ['nested' => $recipe];
        }
        $html = '<html><head><script type="application/ld+json">'
            .json_encode($recipe, JSON_THROW_ON_ERROR)
            .'</script></head><body>Client-side application only.</body></html>';

        try {
            app(WebpageRecipeExtractor::class)->extract($html);
            $this->fail('Overly deep structured data should not be accepted.');
        } catch (WebpageFetchException $exception) {
            $this->assertSame('recipe_structure_not_found', $exception->safeErrorCode);
        }
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/RecipeImports/Webpages/'.$name)) ?: '';
    }
}
