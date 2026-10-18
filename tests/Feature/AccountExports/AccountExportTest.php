<?php

namespace Tests\Feature\AccountExports;

use App\Audit\AuditEventRecorder;
use App\Domain\AccountExports\BuildAccountExport;
use App\Domain\AccountExports\CleanupAccountExport;
use App\Domain\AccountExports\ExportContract;
use App\Domain\AccountExports\RequestAccountExport;
use App\Domain\AccountExports\StoredJson;
use App\Domain\Diary\DiaryEntryWriter;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\RecipeNutritionValueNormalizer;
use App\Jobs\DeleteAccountExport;
use App\Jobs\GenerateAccountExport;
use App\Models\AccountExport;
use App\Models\AuditEvent;
use App\Models\Bookmark;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanShare;
use App\Models\MealPlanSlot;
use App\Models\NutritionTargetProfile;
use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Models\RecipeImport;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeIngredientLineMatch;
use App\Models\RecipeRemixLineage;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Observability\Alerts\AlertSink;
use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\Expectation;
use Mockery\MockInterface;
use Mockery\VerificationDirector;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class AccountExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Date::setTestNow('2026-10-18 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_empty_account_produces_complete_documented_zip_and_idempotent_correlated_audit(): void
    {
        $owner = User::factory()->create();
        $export = $this->requestExport($owner);
        $job = new GenerateAccountExport($export->id, $export->correlation_id);
        $job->handle(app(BuildAccountExport::class));
        $bytes = Storage::disk('local')->get($export->storageKey());
        $job->handle(app(BuildAccountExport::class));
        $this->assertSame($bytes, Storage::disk('local')->get($export->storageKey()));
        $archive = $this->archive($export);
        $this->assertSame('self', $archive['account']['id']);
        $this->assertSame([], $archive['recipes']);
        $this->assertSame([], $archive['diary']['transitions']);
        $this->assertSame('ready', $export->fresh()->state);
        $events = AuditEvent::query()->where('action', 'account.export_event')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['requested', 'generated'], $events->pluck('payload.event')->all());
        $this->assertSame([$export->correlation_id], $events->pluck('correlation_id')->unique()->all());
        $this->assertStringNotContainsString($owner->email, $events->toJson());
    }

    public function test_representative_export_preserves_owned_content_precision_and_history_without_foreign_graphs(): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/London']);
        $foreign = User::factory()->create(['name' => 'FOREIGN-PRIVATE-NAME', 'email' => 'foreign-private@example.test', 'timezone' => 'UTC']);
        $recipe = Recipe::factory()->for($owner, 'owner')->create(['title' => 'Own working wording']);
        $snapshot = ['title' => 'Own immutable wording', 'servings' => '2.00', 'ingredients' => [['position' => 0, 'original_text' => '1 pinch salt, optional', 'quantity' => '1.123456789012345678']], 'steps' => [], 'sections' => [], 'nutrition_estimate' => ['type' => 'estimate', 'is_estimate' => true, 'calculation_policy_version' => 1, 'per_serving' => ['protein' => ['value' => '12.123456789012345678', 'unit' => 'g', 'basis' => 'per_serving', 'status' => 'approximate', 'is_estimate' => true]], 'inputs' => []]];
        $first = RecipeVersion::factory()->for($recipe)->create(['snapshot' => $snapshot]);
        RecipeVersion::factory()->for($recipe)->create(['version_number' => 2, 'snapshot' => [...$snapshot, 'title' => 'Later immutable wording']]);
        $collection = RecipeCollection::factory()->for($owner, 'owner')->create();
        $collection->recipes()->attach($recipe);
        Ingredient::factory()->for($owner)->withNutrition()->create(['name' => 'Owned legacy ingredient']);
        RecipeImport::factory()->for($owner, 'owner')->create(['source_text' => 'Owned retained import', 'recipe_id' => $recipe->id]);
        $proposal = CatalogueCorrectionProposal::factory()->create(['proposer_user_id' => $owner->id, 'reason' => 'Own submitted reason']);
        DB::table('catalogue_correction_changes')->insert(['id' => (string) Str::ulid(), 'proposal_id' => $proposal->id, 'field_key' => 'name', 'before_value' => json_encode(['value' => 'Before']), 'proposed_value' => json_encode(['value' => 'After']), 'created_at' => now()]);
        $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create(['starts_on' => '2026-10-18', 'ends_on' => '2026-10-18']);
        $day = MealPlanDay::factory()->for($plan)->create(['date' => '2026-10-18', 'day_index' => null]);
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();
        $foreignRecipe = Recipe::factory()->for($foreign, 'owner')->create(['title' => 'FOREIGN-LIVE-PRIVATE-TITLE']);
        $foreignVersion = RecipeVersion::factory()->for($foreignRecipe)->create(['snapshot' => ['title' => 'FOREIGN-UNREFERENCED-VERSION']]);
        Bookmark::factory()->for($owner, 'owner')->create(['recipe_id' => $foreignRecipe->id]);
        $entry = MealPlanRecipeEntry::factory()->for($slot, 'slot')->create(['recipe_id' => $foreignRecipe->id, 'recipe_version_id' => $foreignVersion->id, 'recipe_snapshot' => [...$snapshot, 'title' => 'Legitimately owned pinned snapshot', 'imported_nutrition' => ['provenance' => ['recipe_import_id' => 'FOREIGN-IMPORT-SECRET', 'submitted_url' => 'https://example.test/?token=PRIVATE'], 'per_serving' => []]]]);
        MealPlanItemEntry::factory()->for($slot, 'slot')->create(['one_off_wording' => 'Owned snack', 'planned_amount' => '1.123456789012345678']);
        MealPlanShare::factory()->for($plan)->create(['recipient_user_id' => $foreign->id]);
        $secondPlan = MealPlan::factory()->for($owner, 'owner')->create();
        MealPlanShare::factory()->for($secondPlan)->create(['recipient_user_id' => $foreign->id]);
        $foreignPlan = MealPlan::factory()->for($foreign, 'owner')->create(['name' => 'FOREIGN-SHARED-PLAN']);
        MealPlanShare::factory()->for($foreignPlan)->create(['recipient_user_id' => $owner->id]);
        $profile = NutritionTargetProfile::query()->where('user_id', $owner->id)->firstOrFail();
        DB::table('nutrition_targets')->insert(['nutrition_target_profile_id' => $profile->id, 'nutrient' => 'protein', 'type' => 'minimum', 'minimum_value' => '70.123456789012345678']);
        $phaseId = DB::table('meal_plan_target_phases')->insertGetId(['meal_plan_id' => $plan->id, 'nutrition_target_profile_id' => null, 'profile_name_snapshot' => 'Deleted profile name', 'starts_on' => '2026-10-18']);
        DB::table('meal_plan_target_phase_values')->insert(['meal_plan_target_phase_id' => $phaseId, 'effective_on' => '2026-10-18', 'targets' => json_encode(['protein' => ['type' => 'minimum', 'exact_value' => null, 'minimum_value' => '65.123456789012345678', 'maximum_value' => null]])]);
        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]))->assertSessionHasNoErrors();
        $this->patch(route('meal-plans.consumption.update', [$plan, 'recipe', $entry]), ['consumed_local_at' => '2026-10-18 12:15'])->assertSessionHasNoErrors();
        $this->patch(route('meal-plans.consumption.update', [$plan, 'recipe', $entry]), ['actual_amount' => '1.25', 'consumed_local_at' => '2026-10-18 12:15'])->assertSessionHasNoErrors();
        $this->delete(route('meal-plans.consumption.destroy', [$plan, 'recipe', $entry]))->assertSessionHasNoErrors();
        $this->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]), ['actual_amount' => '2.00'])->assertSessionHasNoErrors();
        app(DiaryEntryWriter::class)->create(['kind' => 'one_off', 'one_off_wording' => 'Ad hoc owned snack', 'actual_amount' => '1.125', 'actual_unit' => 'serving'], $owner);
        app(DiaryEntryWriter::class)->create(['kind' => 'one_off', 'one_off_wording' => 'FOREIGN-DIARY', 'actual_amount' => '1', 'actual_unit' => 'serving'], $foreign);
        $export = $this->requestExport($owner);
        app(BuildAccountExport::class)->generate($export->id);
        $data = $this->archive($export);
        $serialized = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['FOREIGN-PRIVATE-NAME', 'foreign-private@example.test', 'FOREIGN-LIVE-PRIVATE-TITLE', 'FOREIGN-UNREFERENCED-VERSION', 'FOREIGN-IMPORT-SECRET', 'FOREIGN-DIARY', 'FOREIGN-SHARED-PLAN', 'PRIVATE'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertCount(2, $data['recipes'][0]['versions']);
        $this->assertSame('recipe-version:'.$first->id, $data['recipes'][0]['versions'][0]['id']);
        $this->assertSame('12.123456789012345678', $data['recipes'][0]['versions'][0]['content']['nutrition_estimate']['per_serving']['protein']['value']);
        $this->assertSame('unavailable', $data['organisation']['recipe_bookmarks'][0]['availability']);
        $this->assertCount(1, $data['organisation']['collections'][0]['memberships']);
        $this->assertCount(2, $data['plans']);
        $this->assertSame('recipient-1', $data['plans'][0]['outgoing_shares'][0]['recipient_alias']);
        $this->assertSame('recipient-1', $data['plans'][1]['outgoing_shares'][0]['recipient_alias']);
        $this->assertSame('1.123456789012345678', $data['plans'][0]['days'][0]['slots'][0]['item_entries'][0]['planned_amount']);
        $this->assertCount(6, $data['diary']['transitions']);
        $transitions = array_slice($data['diary']['transitions'], 0, 5);
        $this->assertSame(['consume', 'correct', 'correct', 'reverse', 'reconsume'], array_column($transitions, 'action'));
        $this->assertSame($transitions[0]['consumption_snapshot_id'], $transitions[1]['consumption_snapshot_id']);
        $this->assertNull($transitions[3]['consumption_snapshot_id']);
        $this->assertSame('70.123456789012345678', $data['targets']['profiles'][0]['targets'][0]['minimum_value']);
        $this->assertSame('Deleted profile name', $data['targets']['phases'][0]['profile_name_snapshot']);
        $this->assertSame('Own submitted reason', $data['proposals']['corrections'][0]['reason']);
        $this->assertSame('After', $data['proposals']['corrections'][0]['changes'][0]['proposed_value']['value']);
        $this->assertSame('Owned retained import', $data['imports'][0]['source_text']);
        $this->assertSame('30.4', $data['proposals']['legacy_ingredients'][0]['nutriments']['per_100g']['carbohydrates']);
    }

    public function test_authenticated_owner_download_expiry_and_cleanup_are_audited_and_idempotent(): void
    {
        $owner = User::factory()->create();
        $export = $this->requestExport($owner);
        app(BuildAccountExport::class)->generate($export->id);
        $url = route('account-exports.download', $export);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['is_administrator' => true]))->get($url)->assertNotFound();
        $response = $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip')->assertHeader('Cache-Control', 'no-store, private');
        $response->assertDownload('vibedietr-account-export-v1-'.$export->id.'.zip');
        $this->assertSame(Storage::disk('local')->get($export->storageKey()), $response->streamedContent());
        Date::setTestNow($export->expires_at);
        $this->get($url)->assertGone();
        $this->artisan('account-exports:cleanup')->assertSuccessful();
        Queue::assertPushed(DeleteAccountExport::class, fn ($job) => $job->exportId === $export->id && $job->correlationId === $export->correlation_id);
        $job = new DeleteAccountExport($export->id, $export->correlation_id);
        $job->handle(app(CleanupAccountExport::class));
        $job->handle(app(CleanupAccountExport::class));
        Storage::disk('local')->assertMissing($export->storageKey());
        $this->assertSame('deleted', $export->fresh()->state);
        $this->assertSame(['requested', 'generated', 'downloaded', 'expired', 'deleted'], AuditEvent::query()->where('action', 'account.export_event')->get()->pluck('payload.event')->all());
    }

    public function test_repeat_requests_reuse_export_without_duplicate_dispatch_and_failed_or_expired_requests_can_be_replaced(): void
    {
        $owner = User::factory()->create();
        $first = $this->requestExport($owner);
        $this->assertSame($first->id, $this->requestExport($owner)->id);
        Queue::assertPushed(GenerateAccountExport::class, 1);
        app(BuildAccountExport::class)->generate($first->id);
        $this->assertSame($first->id, $this->requestExport($owner)->id);
        Date::setTestNow($first->expires_at);
        $this->assertNotSame($first->id, $this->requestExport($owner)->id);
        app(BuildAccountExport::class)->generate($first->id);
        $this->assertCount(1, AuditEvent::query()->where('action', 'account.export_event')->get()->where('payload.event', 'generated'));
    }

    public function test_unclassified_nested_fields_fail_closed_and_never_publish_partial_archive(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->create();
        RecipeVersion::factory()->for($recipe)->create(['snapshot' => ['title' => 'Owned', 'new_private_relationship' => ['email' => 'private@example.test']]]);
        $export = $this->requestExport($owner);
        $job = (new GenerateAccountExport($export->id))->withFakeQueueInteractions();
        $job->handle(app(BuildAccountExport::class));
        $job->assertFailedWith(NonRetryableJobException::class);
        $this->assertSame('failed', $export->fresh()->state);
        Storage::disk('local')->assertMissing($export->storageKey());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_retry_after_uploaded_file_and_audit_failure_publishes_once_and_cleans_staging(): void
    {
        $owner = User::factory()->create();
        $export = $this->requestExport($owner);
        $recorder = \Mockery::mock(AuditEventRecorder::class);
        $this->expectCall($recorder, 'record')->once()->andThrow(new RuntimeException('private database contents'));
        $this->instance(AuditEventRecorder::class, $recorder);
        try {
            (new GenerateAccountExport($export->id))->handle(app(BuildAccountExport::class));
            $this->fail('The simulated audit failure must roll back publication.');
        } catch (RetryableJobException $exception) {
            $this->assertStringNotContainsString('private database contents', serialize($exception));
        }
        $this->assertSame('queued', $export->fresh()->state);
        Storage::disk('local')->assertExists($export->storageKey());
        $this->assertFileDoesNotExist($export->stagingPath());
        $this->actingAs($owner)->get(route('account-exports.download', $export))->assertGone();
        $this->app->forgetInstance(AuditEventRecorder::class);
        app(BuildAccountExport::class)->generate($export->id);
        $this->assertSame('ready', $export->fresh()->state);
        $this->assertCount(1, AuditEvent::query()->where('action', 'account.export_event')->get()->where('payload.event', 'generated'));
    }

    public function test_cleanup_retries_failed_deletion_without_duplicate_expiry_and_removes_crash_staging(): void
    {
        $export = $this->requestExport(User::factory()->create());
        app(BuildAccountExport::class)->generate($export->id);
        file_put_contents($export->stagingPath(), 'synthetic crashed staging');
        Date::setTestNow($export->expires_at);
        $disk = \Mockery::mock(Filesystem::class);
        $this->expectCall($disk, 'exists')->twice()->andReturn(true);
        $this->expectCall($disk, 'delete')->twice()->andReturn(false, true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(CleanupAccountExport::class)->cleanup($export->id);
            $this->fail('Failed storage deletion must remain replayable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('export_cleanup_failed', $exception->getMessage());
        }
        $this->assertSame('expired', $export->fresh()->state);
        app(CleanupAccountExport::class)->cleanup($export->id);
        $this->assertSame('deleted', $export->fresh()->state);
        $this->assertFileDoesNotExist($export->stagingPath());
        $this->assertSame(['requested', 'generated', 'expired', 'deleted'], AuditEvent::query()->where('action', 'account.export_event')->get()->pluck('payload.event')->all());
    }

    public function test_jobs_have_metadata_only_payloads_safe_failures_and_shared_concurrency_protection(): void
    {
        $export = $this->requestExport(User::factory()->create(['email' => 'payload-secret@example.test']));
        $generate = (new GenerateAccountExport($export->id, $export->correlation_id))->withFakeQueueInteractions();
        $delete = (new DeleteAccountExport($export->id, $export->correlation_id))->withFakeQueueInteractions();
        $this->assertSame($generate->middleware()[0]->getLockKey($generate), $delete->middleware()[0]->getLockKey($delete));
        foreach ([$generate, $delete] as $job) {
            $this->assertSame('default', $job->queue);
            $this->assertTrue($job->afterCommit);
            $this->assertSame(3, $job->tries);
            $this->assertSame(60, $job->timeout);
            $this->assertSame([10, 60], $job->backoff());
            $this->assertStringNotContainsString('payload-secret@example.test', serialize($job));
            $middleware = $job->middleware()[0];
            $lock = Cache::lock($middleware->getLockKey($job), 75);
            $this->assertTrue($lock->get());
            try {
                $middleware->handle($job, fn () => $this->fail('A concurrent export operation must be released.'));
            } finally {
                $lock->release();
            }
            $job->assertReleased(10);
        }
        $logs = Log::spy();
        $generate->failed(new RuntimeException('payload-secret@example.test'));
        /** @var VerificationDirector $verification */
        $verification = $logs->shouldHaveReceived('error');
        $verification->with('queued_job_failed', \Mockery::on(function ($context) use ($export) {
            return $context['correlation_id'] === $export->correlation_id
                && ! str_contains(json_encode($context), 'payload-secret@example.test');
        }))->once();
        $this->assertSame('failed', $export->fresh()->state);
        $this->assertNotSame($export->id, $this->requestExport(User::query()->findOrFail($export->user_id))->id);
    }

    public function test_missing_account_and_missing_export_cleanup_are_safe_and_export_page_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $export = $this->requestExport($owner);
        app(BuildAccountExport::class)->generate($export->id);
        $other = $this->requestExport(User::factory()->create());
        $this->actingAs($owner)->get(route('account-exports.index'))->assertOk()->assertSee(route('account-exports.download', $export))->assertDontSee($other->id);
        $this->actingAs($owner)->post(route('account-exports.store'), ['user_id' => $other->user_id])->assertRedirect(route('account-exports.index'));
        $this->assertDatabaseCount('account_exports', 2);
        DB::table('users')->where('id', $owner->id)->delete();
        app(CleanupAccountExport::class)->cleanup($export->id);
        Storage::disk('local')->assertMissing($export->storageKey());
        $this->assertSame('deleted', $export->fresh()->state);
        app(BuildAccountExport::class)->generate((string) Str::ulid());
        app(CleanupAccountExport::class)->cleanup((string) Str::ulid());
    }

    public function test_stored_json_and_all_nutrient_statuses_preserve_exact_tokens_without_display_rounding(): void
    {
        $values = StoredJson::decode('{"protein": {"value":12.123456789012345678,"status":"known"},"fibre":{"value":null,"status":"missing"},"salt":{"value":null,"status":"trace"},"sugars":{"value":0.000000000000000000,"status":"known"}}');
        $projected = (array) app(ExportContract::class)->project('values', $values);
        $this->assertSame('12.123456789012345678', $projected['protein']->value);
        $this->assertSame('0.000000000000000000', $projected['sugars']->value);
        $this->assertNull($projected['fibre']->value);
        $this->assertSame('trace', $projected['salt']->status);
        $transition = app(ExportContract::class)->project('provenance', ['extracted_at' => '2026-10-18 12:30:40.123456']);
        $this->assertSame('2026-10-18T12:30:40.123456Z', $transition->extracted_at);
        foreach (Nutrient::cases() as $nutrient) {
            $value = app(ExportContract::class)->project('values', [$nutrient->value => ['value' => '1.123456789012345678', 'status' => 'below_limit', 'threshold_value' => '2.000000000000000000', 'unit' => 'g', 'basis' => 'per_100g']]);
            $this->assertSame('1.123456789012345678', $value->{$nutrient->value}->value);
        }
    }

    public function test_large_synthetic_archive_stays_within_worker_budget_and_can_be_read_back(): void
    {
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->for($owner, 'owner')->create();
        $day = MealPlanDay::factory()->for($plan)->create();
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();
        $rows = [];
        for ($i = 0; $i < 1500; $i++) {
            $rows[] = ['meal_plan_slot_id' => $slot->id, 'kind' => 'one_off', 'planned_amount' => '1.123456789012345678', 'planned_unit' => 'serving', 'one_off_wording' => str_repeat('Synthetic owned wording. ', 8)];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('meal_plan_item_entries')->insert($chunk);
        }
        $export = $this->requestExport($owner);
        $start = microtime(true);
        app(BuildAccountExport::class)->generate($export->id);
        $elapsed = microtime(true) - $start;
        $data = $this->archive($export);
        $this->assertCount(1500, $data['plans'][0]['days'][0]['slots'][0]['item_entries']);
        $this->assertLessThan(40, $elapsed);
        $this->assertLessThan(192 * 1024 * 1024, memory_get_usage(true));
        File::ensureDirectoryExists(storage_path('app/testing/dep07'));
        file_put_contents(storage_path('app/testing/dep07/scale.json'), json_encode(['items' => 1500, 'elapsed_seconds' => $elapsed, 'allocated_bytes' => memory_get_usage(true), 'zip_bytes' => Storage::disk('local')->size($export->storageKey())], JSON_PRETTY_PRINT));
    }

    public function test_owned_revision_nutrition_histories_and_submission_facts_keep_provenance_without_remix_identity(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->for($owner, 'owner')->finalizedWithActiveRevision()->create();
        $version = $recipe->currentVersion()->firstOrFail();
        $item = CatalogueItem::factory()->submittedBy($owner)->create();
        $catalogueVersion = CatalogueItemVersion::factory()->current()->completeNutrition()->create(['catalogue_item_id' => $item->id, 'name' => 'Own submission facts']);
        $line = RecipeIngredientLine::factory()->for($recipe)->create();
        RecipeIngredientLineMatch::factory()->needsReview()->create(['recipe_ingredient_line_id' => $line->id, 'catalogue_item_version_id' => $catalogueVersion->id]);
        RecipeRemixLineage::factory()->whoseSourceIsPrivate()->create(['remix_recipe_id' => $recipe->id]);
        $values = app(RecipeNutritionValueNormalizer::class)->normalize(['protein' => '12.123456789012345678', 'energy_kcal' => '100'], 'creator_override');
        DB::table('recipe_nutrition_override_events')->insert(['id' => (string) Str::ulid(), 'recipe_version_id' => $version->id, 'actor_user_id' => $owner->id, 'event' => 'added', 'prior_source' => 'ingredient_estimate', 'resulting_source' => 'creator_override', 'prior_values' => '{}', 'resulting_values' => json_encode($values), 'occurred_at' => now(), 'audit_event_id' => (string) Str::ulid()]);
        DB::table('recipe_nutrition_recalculations')->insert(['id' => (string) Str::ulid(), 'recipe_version_id' => $version->id, 'approved_catalogue_item_version_id' => $catalogueVersion->id, 'correlation_id' => 'synthetic-recalculation', 'state' => 'completed', 'estimate' => json_encode(['type' => 'estimate', 'is_estimate' => true, 'calculation_policy_version' => 1, 'per_serving' => ['protein' => ['value' => '8.123456789012345678', 'status' => 'approximate', 'is_estimate' => true]], 'inputs' => []]), 'completed_at' => now()]);
        RecipeImport::factory()->for($owner, 'owner')->webpage()->create();
        $export = $this->requestExport($owner);
        app(BuildAccountExport::class)->generate($export->id);
        $data = $this->archive($export);
        $exportedVersion = $data['recipes'][0]['versions'][0];
        $this->assertSame('12.123456789012345678', $exportedVersion['effective_nutrition']['values']['protein']['value']);
        $this->assertSame('8.123456789012345678', $exportedVersion['recalculation_history'][0]['estimate']['per_serving']['protein']['value']);
        $this->assertEquals($version->snapshot['nutrition_estimate'] ?? [], $exportedVersion['content']['nutrition_estimate'] ?? []);
        $this->assertCount(1, $exportedVersion['override_history']);
        $this->assertNotNull($data['recipes'][0]['active_revision']);
        $this->assertSame('unavailable', $data['organisation']['lineage'][0]['availability']);
        $this->assertArrayNotHasKey('source_creator_user_id', $data['organisation']['lineage'][0]);
        $this->assertSame('0.950000000000000000', $data['recipes'][0]['content']['ingredients'][0]['catalogue_match']['candidate_score']);
        $this->assertCount(10, $data['proposals']['catalogue_submissions'][0]['submitted_version']['observations']);
        $this->assertCount(10, $data['proposals']['catalogue_submissions'][0]['submitted_version']['nutrient_values']);
        $this->assertNull($data['imports'][0]['source_text']);
    }

    public function test_retention_monitor_alert_contains_only_count_and_threshold_requires_overdue_export(): void
    {
        $export = $this->requestExport(User::factory()->create(['email' => 'retention-private@example.test']));
        $sink = \Mockery::mock(AlertSink::class);
        $this->expectCall($sink, 'send')->with('account_export_retention', 'critical', ['count' => 1])->once();
        $this->expectCall($sink, 'send')->withArgs(fn ($category) => $category !== 'account_export_retention')->zeroOrMoreTimes();
        $this->instance(AlertSink::class, $sink);
        $this->artisan('observability:monitor')->assertSuccessful();
        Date::setTestNow($export->expires_at->addMinutes(5));
        $this->artisan('observability:monitor')->assertSuccessful();
    }

    private function expectCall(MockInterface $mock, string $method): Expectation
    {
        $expectation = $mock->shouldReceive($method);
        $this->assertInstanceOf(Expectation::class, $expectation);

        return $expectation;
    }

    private function requestExport(User $owner): AccountExport
    {
        return app(RequestAccountExport::class)->request($owner, 'export-test:'.Str::ulid());
    }

    private function archive(AccountExport $export): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->storageKey())));
        $this->assertSame(13, $zip->numFiles);
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $manifest['schema_version']);
        $schema = json_decode($zip->getFromName($manifest['schema_path']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertStringNotContainsString('<script', $zip->getFromName('index.html'));
        $result = [];
        foreach ($manifest['files'] as $file) {
            $bytes = $zip->getFromName($file['path']);
            $this->assertSame($file['bytes'], strlen($bytes));
            if (! str_starts_with($file['path'], 'data/')) {
                continue;
            }
            $envelope = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            $domain = basename($file['path'], '.json');
            app(ExportContract::class)->project($domain.'_envelope', $envelope);
            $result[$domain] = $envelope['data'];
        }
        $zip->close();

        return $result;
    }
}
