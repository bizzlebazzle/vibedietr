<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueCandidateRecorder;
use App\Domain\Catalogue\CatalogueDuplicateEvidence;
use App\Domain\Catalogue\CatalogueModeration;
use App\Domain\Catalogue\CatalogueModerationAuthorization;
use App\Domain\Recipes\RecipeIngredientMatchManager;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\User;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CatalogueModerationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // Committed worker fixtures must not leak into transactional test classes.
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_concurrent_opposite_pair_insertions_reuse_one_database_pair(): void
    {
        $a = CatalogueItem::factory()->create();
        $b = CatalogueItem::factory()->create();
        $one = $this->worker(['pair', $a->id, $b->id]);
        $two = $this->worker(['pair', $b->id, $a->id]);
        DB::beginTransaction();
        try {
            CatalogueItem::query()->whereKey($a->id)->lockForUpdate()->firstOrFail();
            $one->start();
            $two->start();
            $this->assertTrue($this->awaitOutput($one, 'ready'));
            $this->assertTrue($this->awaitOutput($two, 'ready'));
            DB::commit();
            $this->assertSame(0, $one->wait(), $one->getErrorOutput());
            $this->assertSame(0, $two->wait(), $two->getErrorOutput());
            $this->assertSame($one->getOutput(), $two->getOutput());
            $this->assertDatabaseCount('catalogue_duplicate_candidates', 1);
            $this->assertDatabaseCount('audit_events', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $one->stop();
            $two->stop();
        }
    }

    public function test_merge_sees_match_committed_after_its_repeatable_read_snapshot(): void
    {
        $administrator = User::factory()->administrator()->create(['password' => 'correct-password']);
        $session = app('session.store');
        $enrollment = app(SecondFactorEnrollmentService::class);
        $presentation = $enrollment->begin($administrator, 'correct-password', app(RecentAuthentication::class), $session);
        $enrollment->confirm($administrator, app(TotpEngine::class)->codeAt($presentation->manualKey, app(TotpEngine::class)->currentTimestep()), '192.0.2.5');
        $enrollment->acknowledgeRecoveryCodes($administrator);
        $a = CatalogueItem::factory()->approved()->create();
        $b = CatalogueItem::factory()->approved()->create();
        CatalogueItemVersion::factory()->current()->create(['catalogue_item_id' => $a->id, 'name' => 'Source']);
        CatalogueItemVersion::factory()->current()->create(['catalogue_item_id' => $b->id, 'name' => 'Canonical']);
        $a->refresh();
        $b->refresh();
        $candidate = app(CatalogueCandidateRecorder::class)->record($a->id, $b->id, CatalogueDuplicateEvidence::ExactPrimaryName);
        app(RecentAuthentication::class)->rememberFreshFactor($administrator, CatalogueModerationAuthorization::OPERATION, $session);
        app(CatalogueModeration::class)->confirmDuplicate($candidate->id, $b->id, $administrator, $session, [
            'revision' => 0, 'first_version_id' => $a->current_catalogue_item_version_id, 'second_version_id' => $b->current_catalogue_item_version_id,
            'reason_code' => 'duplicate', 'identity_reviewed' => true,
        ]);
        $recipe = Recipe::factory()->validDraft()->create();
        $owner = User::query()->findOrFail($recipe->user_id);
        $worker = $this->worker(['merge', $administrator->id, $candidate->id, $b->id]);
        DB::beginTransaction();
        try {
            CatalogueItem::query()->whereKey($a->id)->lockForUpdate()->firstOrFail();
            $worker->start();
            $this->assertTrue($this->awaitOutput($worker, 'snapshot-established'));
            $match = app(RecipeIngredientMatchManager::class)->select($recipe->id, $recipe->ingredientLines()->first()->id, $a->id, $a->current_catalogue_item_version_id, $owner);
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $this->assertSame($b->current_catalogue_item_version_id, $match->fresh()->catalogue_item_version_id);
            $this->assertDatabaseCount('catalogue_reference_moves', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker->stop();
        }
    }

    public function test_two_moderators_cannot_confirm_opposite_canonical_choices(): void
    {
        $administrators = [];
        foreach (range(1, 2) as $number) {
            $user = User::factory()->administrator()->create(['password' => 'correct-password']);
            $enrollment = app(SecondFactorEnrollmentService::class);
            $presentation = $enrollment->begin($user, 'correct-password', app(RecentAuthentication::class), app('session.store'));
            $enrollment->confirm($user, app(TotpEngine::class)->codeAt($presentation->manualKey, app(TotpEngine::class)->currentTimestep()), '192.0.2.5');
            $enrollment->acknowledgeRecoveryCodes($user);
            $administrators[] = $user;
        }
        $a = CatalogueItem::factory()->approved()->create();
        $b = CatalogueItem::factory()->approved()->create();
        CatalogueItemVersion::factory()->current()->create(['catalogue_item_id' => $a->id, 'name' => 'First']);
        CatalogueItemVersion::factory()->current()->create(['catalogue_item_id' => $b->id, 'name' => 'Second']);
        $candidate = app(CatalogueCandidateRecorder::class)->record($a->id, $b->id, CatalogueDuplicateEvidence::ExactPrimaryName);
        $one = $this->worker(['confirm', $administrators[0]->id, $candidate->id, $a->id]);
        $two = $this->worker(['confirm', $administrators[1]->id, $candidate->id, $b->id]);
        DB::beginTransaction();
        try {
            CatalogueItem::query()->whereKey($a->id)->lockForUpdate()->firstOrFail();
            $one->start();
            $two->start();
            $this->assertTrue($this->awaitOutput($one, 'snapshot-established'));
            $this->assertTrue($this->awaitOutput($two, 'snapshot-established'));
            DB::commit();
            $outcomes = [$one->wait(), $two->wait()];
            sort($outcomes);
            $this->assertSame([0, 3], $outcomes, $one->getErrorOutput().$two->getErrorOutput());
            $this->assertDatabaseCount('catalogue_moderation_decisions', 1);
            $this->assertSame('confirmed_duplicate', $candidate->fresh()->status->value);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $one->stop();
            $two->stop();
        }
    }

    private function awaitOutput(Process $process, string $marker): bool
    {
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($process->getOutput(), $marker)) {
                return true;
            }
            if (! $process->isRunning()) {
                return false;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /** @param list<int|string> $arguments */
    private function worker(array $arguments): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Fixtures/catalogue-moderation-worker.php'), ...array_map('strval', $arguments)], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ], timeout: 30);
    }
}
