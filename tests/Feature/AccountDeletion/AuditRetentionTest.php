<?php

namespace Tests\Feature\AccountDeletion;

use App\Administrator\AdministratorLifecycleAudit;
use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditIntegrity;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\PruneAuditRetention;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class AuditRetentionTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    public function test_security_expiry_uses_original_six_calendar_month_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-31 10:00:00 UTC'));
        $owner = User::factory()->create();
        $event = app(AuditEventRecorder::class)->record(AuditAction::AccountLifecycleEvent,
            AuditActor::system(), AuditSubject::user($owner), ['event' => 'requested', 'outcome' => 'completed']);
        $this->travelTo(CarbonImmutable::parse('2026-07-31 09:59:59 UTC'));
        app(PruneAuditRetention::class)->run();
        $this->assertDatabaseHas('audit_events', ['id' => $event->id]);
        $this->travel(1)->seconds();
        app(PruneAuditRetention::class)->run();
        $this->assertDatabaseMissing('audit_events', ['id' => $event->id]);
    }

    public function test_purge_destroys_ordinary_mapping_but_preserves_only_dedicated_operator_accountability_until_original_expiry(): void
    {
        $this->freezeSecond();
        $operator = User::factory()->administrator()->create();
        User::factory()->administrator()->create();
        $event = app(AdministratorLifecycleAudit::class)->user($operator, $operator,
            'promotion_initiated', 'completed', 'ordinary', 'ordinary', (string) Str::ulid());
        $expiry = $event->occurred_at->addMonthsNoOverflow(12);
        $request = app(AccountLifecycle::class)->request($operator, waive: true);
        app(PurgeAccount::class)->purge($request->id);
        $this->assertDatabaseMissing('audit_actor_identities', ['user_id' => $operator->id]);
        $mapping = DB::table('erasure_operator_mappings')->where('event_id', $event->id)->sole();
        $this->assertSame($operator->email, Crypt::decryptString($mapping->protected_reference));
        $this->assertTrue(app(AuditIntegrity::class)->verify($event->fresh()));
        $this->assertNull($event->fresh()->subject_identity_id);
        $this->travelTo($expiry);
        app(PruneAuditRetention::class)->run();
        $this->assertDatabaseMissing('erasure_operator_mappings', ['event_id' => $event->id]);
        $this->assertDatabaseMissing('audit_events', ['id' => $event->id]);
        $this->assertDatabaseCount('account_erasure_receipts', 0);
    }

    public function test_superseded_public_provenance_uses_the_original_supersession_clock_after_version_removal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-01-01 10:00:00 UTC'));
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->finalizedPublic()->for($owner, 'owner')->create();
        $old = $recipe->currentVersion;
        $event = app(AuditEventRecorder::class)->record(AuditAction::RecipeFinalized, AuditActor::authenticatedUser($owner),
            AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:'.$recipe->id),
            ['event' => 'finalized', 'outcome' => 'completed', 'version_id' => $old->id, 'visibility' => 'public']);
        $this->travelTo(CarbonImmutable::parse('2025-07-01 10:00:00 UTC'));
        $new = RecipeVersion::factory()->for($recipe)->create(['version_number' => 2]);
        $recipe->forceFill(['current_recipe_version_id' => $new->id])->save();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00 UTC'));
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        app(PurgeAccount::class)->purge($request->id);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $old->id]);
        $this->assertTrue(app(AuditIntegrity::class)->verify($event->fresh()));
        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:59:59 UTC'));
        app(PruneAuditRetention::class)->run();
        $this->assertDatabaseHas('audit_events', ['id' => $event->id]);
        $this->travel(1)->seconds();
        app(PruneAuditRetention::class)->run();
        $this->assertDatabaseMissing('audit_events', ['id' => $event->id]);
    }

    public function test_tampered_audit_evidence_is_reported_and_never_used_as_a_retention_clock(): void
    {
        $owner = User::factory()->create();
        $event = app(AuditEventRecorder::class)->record(AuditAction::AccountLifecycleEvent,
            AuditActor::system(), AuditSubject::user($owner), ['event' => 'requested', 'outcome' => 'completed']);
        DB::table('audit_events')->where('id', $event->id)->update(['occurred_at' => now()->subYears(2)]);
        $result = app(PruneAuditRetention::class)->run();
        $this->assertSame(1, $result['missing_clocks']);
        $this->assertDatabaseHas('audit_events', ['id' => $event->id]);
    }
}
