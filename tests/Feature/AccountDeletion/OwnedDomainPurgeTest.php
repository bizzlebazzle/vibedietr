<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Domain\Nutrition\RecipeNutritionOverrideManager;
use App\Domain\Nutrition\RecipeNutritionSourceSelector;
use App\Domain\Recipes\RecipeRemixCreator;
use App\Domain\Recipes\RecipeVisibility;
use App\Domain\Recipes\RecipeVisibilityChanger;
use App\Models;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class OwnedDomainPurgeTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    public function test_private_owned_domains_are_removed_shared_facts_are_anonymized_and_remixes_survive(): void
    {
        $owner = Models\User::factory()->create();
        $other = Models\User::factory()->create();
        $public = Models\Recipe::factory()->finalizedPublic()->for($owner, 'owner')->create();
        $private = Models\Recipe::factory()->validDraft()->for($owner, 'owner')->create();
        $profile = Models\PublicProfile::factory()->enabled()->create(['user_id' => $owner->id]);
        Models\RecipeCollection::factory()->create(['user_id' => $owner->id]);
        Models\PrivateRecipeTag::factory()->create(['user_id' => $owner->id]);
        Models\Bookmark::factory()->create(['user_id' => $owner->id]);
        Models\NutritionTargetProfile::factory()->create(['user_id' => $owner->id]);
        Models\RecipeImport::factory()->create(['user_id' => $owner->id, 'recipe_id' => $private->id]);
        $ingredient = Models\Ingredient::factory()->create(['user_id' => $owner->id]);
        $mapping = Models\LegacyIngredientCatalogueMapping::factory()->create([
            'ingredient_id' => $ingredient->id, 'legacy_user_id' => $owner->id,
            'legacy_snapshot' => ['name' => 'Shared factual name', 'user_id' => $owner->id],
        ]);
        $catalogue = Models\CatalogueItem::factory()->submittedBy($owner)->create();
        $candidate = Models\CatalogueDuplicateCandidate::factory()->create([
            'submitted_by_user_id' => $owner->id, 'distinction_explanation' => 'Synthetic private explanation',
        ]);
        $pending = Models\CatalogueCorrectionProposal::factory()->withNameChange()->create(['proposer_user_id' => $owner->id]);
        $accepted = Models\CatalogueCorrectionProposal::factory()->accepted()->withNameChange()->create(['proposer_user_id' => $owner->id]);
        $term = Models\ManagedRecipeTermSuggestion::factory()->create(['recipe_id' => $public->id, 'suggested_by_user_id' => $owner->id]);
        $remix = app(RecipeRemixCreator::class)->create($public->id, $public->current_recipe_version_id, (string) Str::ulid(), $other);
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        app(PurgeAccount::class)->purge($request->id);
        foreach (['public_profiles', 'recipe_collections', 'private_recipe_tags', 'bookmarks', 'nutrition_target_profiles',
            'recipe_imports', 'ingredients'] as $table) {
            $this->assertDatabaseMissing($table, ['user_id' => $owner->id]);
        }
        $this->assertDatabaseMissing('public_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('catalogue_correction_proposals', ['id' => $pending->id]);
        $this->assertDatabaseMissing('catalogue_correction_changes', ['proposal_id' => $pending->id]);
        $this->assertNull($accepted->fresh()->proposer_user_id);
        $this->assertSame('', $accepted->fresh()->reason);
        $this->assertDatabaseHas('catalogue_correction_changes', ['proposal_id' => $accepted->id]);
        $this->assertNull($catalogue->fresh()->submitted_by_user_id);
        $this->assertNull($candidate->fresh()->submitted_by_user_id);
        $this->assertNull($candidate->fresh()->distinction_explanation);
        $this->assertNull($mapping->fresh()->legacy_user_id);
        $this->assertArrayNotHasKey('user_id', $mapping->fresh()->legacy_snapshot);
        $this->assertDatabaseMissing('managed_recipe_term_suggestions', ['id' => $term->id]);
        $this->assertSame($other->id, $remix->fresh()->user_id);
        $this->assertNull($remix->fresh()->remixLineage->source_creator_user_id);
        $this->assertDatabaseMissing('audit_actor_identities', ['user_id' => $owner->id]);
    }

    public function test_a_current_recipe_made_public_after_private_publication_remains_anonymized_public_content(): void
    {
        $owner = Models\User::factory()->create();
        $recipe = Models\Recipe::factory()->finalizedPrivate()->for($owner, 'owner')->create();
        app(RecipeVisibilityChanger::class)->change($recipe->id, RecipeVisibility::Public, $owner);
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        app(PurgeAccount::class)->purge($request->id);
        $this->assertNull($recipe->fresh()->user_id);
        $this->get(route('recipes.show', $recipe))->assertOk();
    }

    public function test_public_nutrition_values_survive_without_private_override_history_or_notes(): void
    {
        $owner = Models\User::factory()->create();
        $public = Models\Recipe::factory()->finalizedPublic()->for($owner, 'owner')->create();
        $version = $public->currentVersion;
        $override = app(RecipeNutritionOverrideManager::class)->save($public->id, $version->id,
            ['protein' => '12.3456789'], 'Synthetic private nutrition note', $owner);
        $before = app(RecipeNutritionSourceSelector::class)->effective($version);
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        app(PurgeAccount::class)->purge($request->id);
        $after = app(RecipeNutritionSourceSelector::class)->effective($version->fresh());
        $this->assertSame($before['source'], $after['source']);
        $this->assertSame($before['values'], $after['values']);
        $this->assertDatabaseMissing('recipe_nutrition_override_events', ['id' => $override->id]);
        $this->assertDatabaseMissing('audit_events', ['id' => $override->audit_event_id]);
        $this->assertStringNotContainsString('Synthetic private nutrition note', json_encode($version->fresh()->getAttributes()));
    }
}
