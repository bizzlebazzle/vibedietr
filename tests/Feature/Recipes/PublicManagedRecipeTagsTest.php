<?php

namespace Tests\Feature\Recipes;

use App\Audit\Enums\AuditAction;
use App\Domain\Recipes\ManagedRecipeTermCategory;
use App\Domain\Recipes\ManagedRecipeTermSuggestionStatus;
use App\Domain\Recipes\PublicRecipe;
use App\Models\AuditEvent;
use App\Models\ManagedRecipeTerm;
use App\Models\ManagedRecipeTermSuggestion;
use App\Models\PrivateRecipeTag;
use App\Models\PublicRecipeTag;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicManagedRecipeTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_manage_the_controlled_vocabulary_and_changes_are_audited(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create();

        $this->get(route('admin.managed-recipe-terms.index'))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('admin.managed-recipe-terms.index'))->assertForbidden();
        $this->post(route('admin.managed-recipe-terms.store'), ['category' => 'cuisine', 'name' => 'Italian'])->assertForbidden();

        $this->actingAs($administrator)->get(route('admin.managed-recipe-terms.index'))->assertOk();
        $this->post(route('admin.managed-recipe-terms.store'), [
            'category' => ManagedRecipeTermCategory::Cuisine->value,
            'name' => 'Italian',
        ])->assertRedirect();
        $term = ManagedRecipeTerm::query()->sole();
        $this->assertSame('Italian', $term->name);
        $this->assertTrue($term->is_active);

        $this->patch(route('admin.managed-recipe-terms.update', $term), [
            'name' => 'Regional Italian',
            'is_active' => false,
        ])->assertRedirect();
        $this->assertSame('Regional Italian', $term->fresh()->name);
        $this->assertFalse($term->fresh()->is_active);
        $this->assertDatabaseCount('audit_events', 3);
        $this->assertSame(3, AuditEvent::query()->where('action', AuditAction::ManagedRecipeVocabularyChanged)->count());
        AuditEvent::query()->where('action', AuditAction::ManagedRecipeVocabularyChanged)->get()->each(function (AuditEvent $event): void {
            $this->assertEqualsCanonicalizing(['action', 'category', 'outcome'], array_keys($event->payload));
            $this->assertStringNotContainsString('Italian', json_encode($event->payload, JSON_THROW_ON_ERROR));
        });

        $this->actingAs($user)->patch(route('admin.managed-recipe-terms.update', $term), [
            'name' => 'Forged', 'is_active' => true,
        ])->assertForbidden();
        $this->assertSame('Regional Italian', $term->fresh()->name);
    }

    public function test_creator_free_form_tags_are_validated_normalized_idempotent_and_owner_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();

        $this->post(route('recipes.public-tags.store', $recipe), ['name' => 'Guest'])->assertRedirect(route('login'));
        $this->actingAs($other)->post(route('recipes.public-tags.store', $recipe), ['name' => 'Forged'])->assertForbidden();
        $this->actingAs($owner)->post(route('recipes.public-tags.store', $recipe), ['name' => '  Quick   Dinner  '])->assertRedirect();
        $this->post(route('recipes.public-tags.store', $recipe), ['name' => 'quick dinner'])->assertRedirect();
        $this->assertDatabaseCount('public_recipe_tags', 1);
        $tag = PublicRecipeTag::query()->sole();
        $this->assertSame('Quick   Dinner', $tag->name);
        $this->assertSame('quick dinner', $tag->normalized_name);

        $this->post(route('recipes.public-tags.store', $recipe), ['name' => '   '])->assertSessionHasErrors('name');
        $this->actingAs($other)->delete(route('recipes.public-tags.destroy', [$recipe, $tag]))->assertForbidden();
        $this->actingAs($owner)->delete(route('recipes.public-tags.destroy', [$recipe, $tag]))->assertRedirect();
        $this->assertDatabaseCount('public_recipe_tags', 0);
    }

    public function test_creator_can_attach_only_active_managed_terms_and_private_tags_remain_distinct(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();
        $active = ManagedRecipeTerm::factory()->dietary()->create(['name' => 'Vegetarian']);
        $inactive = ManagedRecipeTerm::factory()->mealType()->inactive()->create(['name' => 'Supper']);
        $private = PrivateRecipeTag::factory()->for($owner, 'owner')->withOwnedRecipe($recipe)->create(['name' => 'Christmas']);

        $this->actingAs($owner)->post(route('recipes.managed-classifications.store', $recipe), ['managed_term_id' => $active->id])->assertRedirect();
        $this->post(route('recipes.managed-classifications.store', $recipe), ['managed_term_id' => $active->id])->assertRedirect();
        $this->assertDatabaseCount('managed_recipe_term_recipes', 1);
        $this->post(route('recipes.managed-classifications.store', $recipe), ['managed_term_id' => $inactive->id])->assertSessionHasErrors('managed_term_id');

        $projection = PublicRecipe::fromCurrentVersion($recipe->fresh()->load('currentVersion'));
        $this->assertSame([], $projection->freeFormTags);
        $this->assertSame('Vegetarian', $projection->managedClassifications[0]['name']);
        $this->assertStringNotContainsString($private->name, json_encode($projection->toArray(), JSON_THROW_ON_ERROR));

        $active->forceFill(['is_active' => false])->save();
        $this->assertSame('Vegetarian', PublicRecipe::fromCurrentVersion($recipe->fresh()->load('currentVersion'))->managedClassifications[0]['name']);
    }

    public function test_administrator_suggestion_requires_creator_acceptance_and_review_is_safe_and_audited(): void
    {
        $administrator = User::factory()->administrator()->create();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();
        $term = ManagedRecipeTerm::factory()->cuisine()->create(['name' => 'Mexican']);

        $this->actingAs($other)->post(route('admin.managed-recipe-term-suggestions.store'), [
            'recipe_id' => $recipe->id, 'managed_term_id' => $term->id,
        ])->assertForbidden();
        $this->actingAs($administrator)->post(route('admin.managed-recipe-term-suggestions.store'), [
            'recipe_id' => $recipe->id, 'managed_term_id' => $term->id,
        ])->assertRedirect();
        $suggestion = ManagedRecipeTermSuggestion::query()->sole();
        $this->assertSame(ManagedRecipeTermSuggestionStatus::Pending, $suggestion->status);
        $this->assertDatabaseCount('managed_recipe_term_recipes', 0);
        $this->post(route('admin.managed-recipe-term-suggestions.store'), [
            'recipe_id' => $recipe->id, 'managed_term_id' => $term->id,
        ])->assertSessionHasErrors('managed_term_id');

        $this->actingAs($other)->post(route('managed-recipe-term-suggestions.accept', $suggestion))->assertForbidden();
        auth()->logout();
        $this->post(route('managed-recipe-term-suggestions.accept', $suggestion))->assertRedirect(route('login'));
        $this->actingAs($owner)->post(route('managed-recipe-term-suggestions.accept', $suggestion))->assertRedirect();
        $this->post(route('managed-recipe-term-suggestions.accept', $suggestion))->assertRedirect();
        $this->assertSame(ManagedRecipeTermSuggestionStatus::Accepted, $suggestion->fresh()->status);
        $this->assertDatabaseCount('managed_recipe_term_recipes', 1);
        $this->assertSame(1, AuditEvent::query()->where('action', AuditAction::RecipeTagSuggestionReviewed)->count());
        $audit = AuditEvent::query()->where('action', AuditAction::RecipeTagSuggestionReviewed)->sole();
        $this->assertEqualsCanonicalizing(['action', 'managed_term_id', 'outcome', 'recipe_id'], array_keys($audit->payload));
        $this->assertStringNotContainsString($owner->email, json_encode($audit->payload, JSON_THROW_ON_ERROR));
    }

    public function test_rejection_does_not_attach_and_inactive_term_cannot_be_approved(): void
    {
        $owner = User::factory()->create();
        $term = ManagedRecipeTerm::factory()->dietary()->create(['name' => 'Vegan']);
        $recipe = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();
        $rejected = ManagedRecipeTermSuggestion::factory()->for($recipe)->for($term, 'term')->create();

        $this->actingAs($owner)->post(route('managed-recipe-term-suggestions.reject', $rejected))->assertRedirect();
        $this->assertSame(ManagedRecipeTermSuggestionStatus::Rejected, $rejected->fresh()->status);
        $this->assertDatabaseCount('managed_recipe_term_recipes', 0);

        $pending = ManagedRecipeTermSuggestion::factory()->for($recipe)->for($term, 'term')->create();
        $term->forceFill(['is_active' => false])->save();
        $this->post(route('managed-recipe-term-suggestions.accept', $pending))->assertSessionHasErrors('decision');
        $this->assertSame(ManagedRecipeTermSuggestionStatus::Pending, $pending->fresh()->status);
    }

    public function test_public_serialization_and_discovery_include_only_accepted_public_metadata(): void
    {
        $owner = User::factory()->create(['email' => 'owner-private@example.test']);
        $administrator = User::factory()->administrator()->create();
        $public = Recipe::factory()->for($owner, 'owner')->finalizedPublic()->create();
        $private = Recipe::factory()->for($owner, 'owner')->finalizedPrivate()->create();
        PublicRecipeTag::factory()->for($public)->create(['name' => 'Comfort food']);
        PublicRecipeTag::factory()->for($private)->create(['name' => 'Comfort food']);
        $accepted = ManagedRecipeTerm::factory()->mealType()->create(['name' => 'Dinner']);
        $pending = ManagedRecipeTerm::factory()->cuisine()->create(['name' => 'Pendingland']);
        $public->managedTerms()->attach($accepted);
        ManagedRecipeTermSuggestion::factory()->for($public)->for($pending, 'term')->for($administrator, 'suggestedBy')->create();
        PrivateRecipeTag::factory()->for($owner, 'owner')->withOwnedRecipe($public)->create(['name' => 'Secret organiser']);

        $array = PublicRecipe::fromCurrentVersion($public->fresh()->load('currentVersion'))->toArray();
        $json = json_encode($array, JSON_THROW_ON_ERROR);
        $this->assertSame(['id', 'title', 'servings', 'visibility', 'version', 'ingredients', 'instructions', 'tags', 'classifications'], array_keys($array));
        $this->assertStringContainsString('Comfort food', $json);
        $this->assertStringContainsString('Dinner', $json);
        $this->assertStringNotContainsString('Pendingland', $json);
        $this->assertStringNotContainsString('Secret organiser', $json);
        $this->assertStringNotContainsString('suggested_by', $json);
        $this->assertStringNotContainsString('status', $json);
        $this->assertStringNotContainsString('verified', $json);

        $this->get(route('recipes.index', ['q' => 'Comfort food']))->assertOk()->assertSee($array['title']);
        $this->get(route('recipes.index', ['q' => 'Dinner']))->assertOk()->assertSee($array['title']);
        $this->get(route('recipes.index', ['q' => 'Pendingland']))->assertOk()->assertSee('No recipes match your search.');
        $this->get(route('recipes.index', ['q' => 'Secret organiser']))->assertOk()->assertSee('No recipes match your search.');
        $this->assertDatabaseHas('recipes', ['id' => $private->id]);
    }

    public function test_free_form_nutrition_wording_never_gains_verification_or_treats_missing_values_as_zero(): void
    {
        $recipe = Recipe::factory()->finalizedPublic()->create();
        PublicRecipeTag::factory()->for($recipe)->create(['name' => 'Low fat']);
        $json = json_encode(PublicRecipe::fromCurrentVersion($recipe->fresh()->load('currentVersion'))->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Low fat', $json);
        $this->assertStringNotContainsString('verified', $json);
        $this->assertStringNotContainsString('nutrition', $json);
        $this->assertStringNotContainsString('0', json_encode(['verification' => null], JSON_THROW_ON_ERROR));
    }

    public function test_distinct_factories_cover_term_categories_states_suggestions_and_recipe_metadata(): void
    {
        $dietary = ManagedRecipeTerm::factory()->dietary()->create();
        $cuisine = ManagedRecipeTerm::factory()->cuisine()->inactive()->create();
        $mealType = ManagedRecipeTerm::factory()->mealType()->create();
        $recipe = Recipe::factory()->finalizedPublic()->create();
        PublicRecipeTag::factory()->for($recipe)->create();
        $recipe->managedTerms()->attach($mealType);
        $pending = ManagedRecipeTermSuggestion::factory()->for($recipe)->for($dietary, 'term')->create();
        $accepted = ManagedRecipeTermSuggestion::factory()->for(Recipe::factory())->for($mealType, 'term')->accepted()->create();
        $rejected = ManagedRecipeTermSuggestion::factory()->for(Recipe::factory())->for($mealType, 'term')->rejected()->create();

        $this->assertSame(ManagedRecipeTermCategory::Dietary, $dietary->category);
        $this->assertSame(ManagedRecipeTermCategory::Cuisine, $cuisine->category);
        $this->assertFalse($cuisine->is_active);
        $this->assertSame(ManagedRecipeTermSuggestionStatus::Pending, $pending->status);
        $this->assertSame(ManagedRecipeTermSuggestionStatus::Accepted, $accepted->status);
        $this->assertSame(ManagedRecipeTermSuggestionStatus::Rejected, $rejected->status);
        $this->assertDatabaseCount('public_recipe_tags', 1);
        $this->assertDatabaseCount('managed_recipe_term_recipes', 1);
    }
}
