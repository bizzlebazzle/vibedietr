<?php

namespace Tests\Feature\Recipes;

use App\Domain\RecipeImports\Extraction\WebpageRecipeExtractor;
use App\Integrations\RecipeWebpages\WebpageFetchException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame('240.000000000000000000', $result->nutrition['per_serving']['energy_kcal']['value']);
        $this->assertSame('240.000000000000000000', $result->nutrition['per_serving']['energy_kj']['value']);
        $this->assertSame('0.125000000000000000', $result->nutrition['per_serving']['sodium']['value']);
        $this->assertSame('sodiumContent', $result->nutrition['observations']['sodium']['source_field']);
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

    /** @return iterable<string, array{bool}> */
    public static function webpageFormats(): iterable
    {
        yield 'JSON-LD' => [true];
        yield 'visible HTML' => [false];
    }

    #[DataProvider('webpageFormats')]
    public function test_webpage_recipe_fields_reconcile_entity_codes_to_characters(bool $structured): void
    {
        if ($structured) {
            $html = '<html><head><script type="application/ld+json">'.json_encode([
                '@type' => 'Recipe', 'name' => 'Terry&#39;s &amp; orange cake', 'recipeYield' => '2',
                'recipeIngredient' => ['300 g Terry&#39;s chocolate orange'],
                'recipeInstructions' => [
                    '@type' => 'HowToSection', 'name' => 'Finish &amp; serve',
                    'itemListElement' => [['@type' => 'HowToStep', 'text' => 'Mix &quot;gently&quot; &#x2014; serve.']],
                ],
            ], JSON_THROW_ON_ERROR).'</script></head><body></body></html>';
        } else {
            $html = '<html><body><h1>Terry&#39;s &amp; orange cake</h1><p>Serves: 2</p>'
                .'<h2>Ingredients</h2><p>300 g Terry&#39;s chocolate orange</p><h2>Instructions</h2>'
                .'<h3>Finish &amp; serve:</h3><p>Mix &quot;gently&quot; &#x2014; serve.</p></body></html>';
        }

        $result = app(WebpageRecipeExtractor::class)->extract($html);

        $this->assertSame($structured ? 'schema_jsonld' : 'visible_text_fallback', $result->method);
        $this->assertSame("Terry's & orange cake", $result->recipe->title);
        $this->assertSame("300 g Terry's chocolate orange", $result->recipe->ingredients[0]->originalText);
        $this->assertSame("Terry's chocolate orange", $result->recipe->ingredients[0]->genericWording);
        $this->assertSame('Finish & serve', $result->recipe->sections[0]->name);
        $this->assertSame('Mix "gently" — serve.', $result->recipe->steps[0]->text);
        if ($structured) {
            $this->assertStringContainsString('Terry&#39;s', $result->sourceText);
        }
    }
}
