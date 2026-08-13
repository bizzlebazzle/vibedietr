<?php

namespace Tests\Feature;

use App\Audit\AuditActor;
use App\Audit\AuditActorIdentityEraser;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditActorType;
use App\Audit\Enums\AuditPurpose;
use App\Audit\Enums\AuditRetentionClass;
use App\Audit\Enums\AuditSubjectType;
use App\Models\AuditActorIdentity;
use App\Models\AuditEvent;
use App\Models\Ingredient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditEventStoreTest extends TestCase
{
    use RefreshDatabase;

    private AuditEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = app(AuditEventRecorder::class);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_a_valid_audit_event_can_be_created(): void
    {
        $event = $this->recordPlanSnapshot();

        $this->assertTrue($event->exists);
        $this->assertSame(AuditAction::PlanSnapshotRecorded, $event->action);
        $this->assertSame(AuditPurpose::ProductHistory, $event->purpose);
        $this->assertSame(
            AuditRetentionClass::PrivateContentUntilFinalPurge,
            $event->retention_class,
        );
        $this->assertSame(1, $event->schema_version);
        $this->assertTrue($event->hasValidIntegrityHash());
    }

    public function test_events_receive_unique_immutable_ulids_that_persist(): void
    {
        $first = $this->recordPlanSnapshot('snapshot:one');
        $second = $this->recordPlanSnapshot('snapshot:two');

        $this->assertTrue(Str::isUlid($first->getKey()));
        $this->assertTrue(Str::isUlid($second->getKey()));
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame($first->getKey(), AuditEvent::findOrFail($first->getKey())->getKey());
    }

    public function test_event_time_is_server_authoritative_and_utc_consistent(): void
    {
        $instant = CarbonImmutable::parse('2026-07-31 12:34:56.123456', 'UTC');
        $this->travelTo($instant);

        $event = $this->recordPlanSnapshot();

        $this->assertSame('UTC', $event->occurred_at->timezoneName);
        $this->assertSame('2026-07-31 12:34:56', $event->occurred_at->format('Y-m-d H:i:s'));
    }

    public function test_an_authenticated_user_actor_uses_an_erasable_identity_mapping(): void
    {
        $user = User::factory()->create();

        $event = $this->recorder->record(
            AuditAction::RecipeNutritionOverrideApplied,
            AuditActor::authenticatedUser($user),
            AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:123'),
            ['changed_nutrients' => ['energy_kcal', 'protein'], 'outcome' => 'applied'],
        );

        $this->assertSame(AuditActorType::AuthenticatedUser, $event->actor_type);
        $this->assertNotNull($event->actor_identity_id);
        $this->assertDatabaseHas('audit_actor_identities', [
            'id' => $event->actor_identity_id,
            'user_id' => $user->id,
        ]);
        $this->assertArrayNotHasKey('email', $event->payload);
        $this->assertArrayNotHasKey('name', $event->payload);
    }

    public function test_erasing_a_user_identity_mapping_retains_the_event_without_reidentification(): void
    {
        $user = User::factory()->create();
        $event = $this->recorder->record(
            AuditAction::RecipeNutritionOverrideApplied,
            AuditActor::authenticatedUser($user),
            AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:123'),
            ['changed_nutrients' => ['salt'], 'outcome' => 'applied'],
        );
        $mappingId = $event->actor_identity_id;

        $erased = app(AuditActorIdentityEraser::class)->eraseForUser($user);
        $retained = $event->fresh();

        $this->assertSame(1, $erased);
        $this->assertDatabaseMissing('audit_actor_identities', ['id' => $mappingId]);
        $this->assertSame($mappingId, $retained->actor_identity_id);
        $this->assertSame(AuditAction::RecipeNutritionOverrideApplied, $retained->action);
        $this->assertTrue($retained->hasValidIntegrityHash());
    }

    public function test_deleting_a_user_nulls_the_mapping_without_deleting_the_event(): void
    {
        $user = User::factory()->create();
        $event = $this->recorder->record(
            AuditAction::RecipeNutritionOverrideApplied,
            AuditActor::authenticatedUser($user),
            AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:123'),
            ['changed_nutrients' => ['fat'], 'outcome' => 'applied'],
        );

        $user->delete();

        $this->assertDatabaseHas('audit_events', ['id' => $event->id]);
        $this->assertDatabaseHas('audit_actor_identities', [
            'id' => $event->actor_identity_id,
            'user_id' => null,
        ]);
    }

    public function test_a_system_actor_has_no_identity_mapping(): void
    {
        $event = $this->recordPlanSnapshot();

        $this->assertSame(AuditActorType::System, $event->actor_type);
        $this->assertNull($event->actor_identity_id);
    }

    public function test_external_operator_and_deployment_actors_are_bounded_and_credential_free(): void
    {
        $target = User::factory()->create();
        $event = $this->recordBootstrap($target, AuditActor::deployment('deploy:release-2026-07-31'));
        $identity = AuditActorIdentity::findOrFail($event->actor_identity_id);

        $this->assertSame(AuditActorType::Deployment, $event->actor_type);
        $this->assertSame('deploy:release-2026-07-31', $identity->external_reference);
        $this->assertNull($identity->user_id);
        $this->assertStringNotContainsString('password', json_encode($event->payload));
        $this->assertStringNotContainsString('token', json_encode($event->payload));
    }

    public function test_a_valid_correlation_identifier_is_accepted(): void
    {
        $target = User::factory()->create();
        $event = $this->recordBootstrap(
            $target,
            correlationId: 'bootstrap:01J4TRACEABLE',
        );

        $this->assertSame('bootstrap:01J4TRACEABLE', $event->correlation_id);
    }

    #[DataProvider('invalidCorrelationIdentifiers')]
    public function test_invalid_or_oversized_correlation_identifiers_are_rejected(string $correlationId): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->recordBootstrap(User::factory()->create(), correlationId: $correlationId);
    }

    public static function invalidCorrelationIdentifiers(): array
    {
        return [
            'oversized' => [str_repeat('a', 65)],
            'contains spaces' => ['request body 123'],
            'raw IP address' => ['203.0.113.8'],
            'looks like a credential-bearing query' => ['trace?token=secret'],
        ];
    }

    public function test_action_purpose_and_retention_are_required_allowlisted_enums(): void
    {
        $event = $this->recordPlanSnapshot();

        $this->assertNull(AuditAction::tryFrom('caller.supplied_action'));
        $this->assertNull(AuditPurpose::tryFrom('caller supplied purpose'));
        $this->assertNull(AuditRetentionClass::tryFrom('forever'));
        $this->assertSame($event->action->purpose(), $event->purpose);
        $this->assertSame($event->action->retentionClass(), $event->retention_class);
    }

    public function test_subject_type_is_allowlisted_and_action_specific(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->recorder->record(
            AuditAction::PlanSnapshotRecorded,
            AuditActor::system(),
            AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:123'),
            ['outcome' => 'recorded', 'snapshot_kind' => 'planned'],
        );
    }

    public function test_user_subjects_cannot_bypass_the_erasable_mapping(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditSubject::resource(AuditSubjectType::UserAccount, '42');
    }

    public function test_unknown_payload_fields_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->recordPlanSnapshot(payload: [
            'outcome' => 'recorded',
            'snapshot_kind' => 'planned',
            'arbitrary_metadata' => 'not allowed',
        ]);
    }

    #[DataProvider('prohibitedPayloads')]
    public function test_prohibited_sensitive_or_private_payload_data_is_rejected(array $extraPayload): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->recordBootstrap(
            User::factory()->create(),
            payload: array_replace($this->bootstrapPayload(), $extraPayload),
        );
    }

    public static function prohibitedPayloads(): array
    {
        return [
            'password' => [['password' => 'correct horse battery staple']],
            'password hash' => [['password_hash' => '$2y$10$example']],
            'access token' => [['access_token' => 'token-value']],
            'authorization header' => [['authorization' => 'Bearer value']],
            'session identifier' => [['session_id' => 'session-value']],
            'raw IP key' => [['ip_address' => '203.0.113.8']],
            'raw IP disguised in allowed field' => [['operation_version' => '203.0.113.8']],
            'full user agent' => [['user_agent' => 'Mozilla/5.0 (X11; Linux x86_64)']],
            'private recipe content' => [['recipe_content' => 'Private family recipe']],
            'ingredient instructions' => [['ingredient_instructions' => 'Secret preparation']],
            'diary content' => [['diary_content' => 'Consumed at noon']],
            'nutrition targets' => [['nutrition_targets' => ['energy_kcal' => 1800]]],
            'OCR source text' => [['ocr_source_text' => 'Extracted private page']],
            'uploaded file' => [['file_content' => 'binary-data']],
            'request body' => [['request_body' => ['anything' => 'value']]],
            'protected evidence' => [['evidence_content' => 'incident exhibit']],
            'secret environment value' => [['environment_value' => 'APP_KEY=value']],
            'command arguments' => [['command_arguments' => '--password=value']],
            'unnecessary email' => [['email' => 'person@example.com']],
            'unnecessary name' => [['full_name' => 'Example Person']],
        ];
    }

    public function test_oversized_payload_content_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->recordBootstrap(
            User::factory()->create(),
            payload: array_replace($this->bootstrapPayload(), [
                'operation_version' => str_repeat('v', 65),
            ]),
        );
    }

    public function test_events_cannot_be_updated_through_the_model_api(): void
    {
        $event = $this->recordPlanSnapshot();
        $event->correlation_id = 'changed';

        $this->expectException(LogicException::class);

        $event->save();
    }

    public function test_events_cannot_be_deleted_through_the_model_api(): void
    {
        $event = $this->recordPlanSnapshot();

        $this->expectException(LogicException::class);

        $event->delete();
    }

    public function test_identity_mappings_can_only_be_deleted_through_the_eraser(): void
    {
        $user = User::factory()->create();
        $event = $this->recorder->record(
            AuditAction::RecipeNutritionOverrideApplied,
            AuditActor::authenticatedUser($user),
            AuditSubject::resource(AuditSubjectType::Recipe, 'recipe:123'),
            ['changed_nutrients' => ['sugars'], 'outcome' => 'applied'],
        );

        $this->expectException(LogicException::class);

        AuditActorIdentity::findOrFail($event->actor_identity_id)->delete();
    }

    public function test_an_evidence_reference_is_stored_without_evidence_content(): void
    {
        $event = $this->recordBootstrap(
            User::factory()->create(),
            evidenceReference: 'protected-case:01J4EVIDENCE',
        );

        $this->assertSame('protected-case:01J4EVIDENCE', $event->evidence_reference);
        $this->assertArrayNotHasKey('evidence', $event->payload);
        $this->assertArrayNotHasKey('evidence_content', $event->payload);
    }

    public function test_integrity_verification_detects_out_of_band_tampering(): void
    {
        $event = $this->recordPlanSnapshot();

        DB::table('audit_events')->where('id', $event->id)->update([
            'payload' => json_encode(['outcome' => 'altered', 'snapshot_kind' => 'planned']),
        ]);

        $this->assertFalse($event->fresh()->hasValidIntegrityHash());
    }

    public function test_migration_is_additive_and_has_a_safe_development_rollback(): void
    {
        $user = User::factory()->create();
        Ingredient::factory()->for($user)->create([
            'name' => 'Existing ingredient',
        ]);
        $migration = require database_path(
            'migrations/2026_07_31_000001_create_audit_event_store.php'
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('audit_events'));
        $this->assertFalse(Schema::hasTable('audit_actor_identities'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('ingredients', ['name' => 'Existing ingredient']);

        $migration->up();

        $this->assertTrue(Schema::hasTable('audit_events'));
        $this->assertTrue(Schema::hasTable('audit_actor_identities'));
    }

    private function recordPlanSnapshot(
        string $subjectIdentifier = 'snapshot:123',
        ?array $payload = null,
    ): AuditEvent {
        return $this->recorder->record(
            AuditAction::PlanSnapshotRecorded,
            AuditActor::system(),
            AuditSubject::resource(AuditSubjectType::PlanSnapshot, $subjectIdentifier),
            $payload ?? ['outcome' => 'recorded', 'snapshot_kind' => 'planned'],
        );
    }

    private function recordBootstrap(
        User $target,
        ?AuditActor $actor = null,
        ?string $correlationId = 'bootstrap:01J4CORRELATION',
        ?string $evidenceReference = null,
        ?array $payload = null,
    ): AuditEvent {
        return $this->recorder->record(
            AuditAction::AdministratorBootstrapCompleted,
            $actor ?? AuditActor::externalOperator('operator:deployment-primary'),
            AuditSubject::user($target),
            $payload ?? $this->bootstrapPayload(),
            $correlationId,
            $evidenceReference,
        );
    }

    private function bootstrapPayload(): array
    {
        return [
            'administrator_count_before' => 0,
            'application_instance_reference' => 'instance:primary',
            'bootstrap_marker_previously_set' => false,
            'configured_target_match' => true,
            'environment_category' => 'production',
            'operation_version' => 'release:2026.07.31',
            'outcome' => 'completed',
            'previous_privilege_state' => 'ordinary',
            'resulting_privilege_state' => 'administrator',
        ];
    }
}
