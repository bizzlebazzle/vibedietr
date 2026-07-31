<?php

namespace Tests\Feature;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuditEventAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_or_browse_internal_audit_records(): void
    {
        $event = $this->moderationEvent();

        $this->assertFalse(Gate::allows('viewAny', AuditEvent::class));
        $this->assertFalse(Gate::allows('view', $event));
    }

    public function test_ordinary_users_cannot_view_browse_or_create_internal_audit_records(): void
    {
        $user = User::factory()->create();
        $event = $this->moderationEvent();
        $gate = Gate::forUser($user);

        $this->assertTrue($gate->denies('viewAny', AuditEvent::class));
        $this->assertTrue($gate->denies('view', $event));
        $this->assertTrue($gate->denies('create', AuditEvent::class));
    }

    public function test_administrators_can_read_scoped_moderation_records_but_cannot_browse_the_store(): void
    {
        $administrator = User::factory()->administrator()->create();
        $event = $this->moderationEvent($administrator);
        $gate = Gate::forUser($administrator);

        $this->assertTrue($gate->allows('view', $event));
        $this->assertTrue($gate->denies('viewAny', AuditEvent::class));
        $this->assertTrue($gate->denies('update', $event));
        $this->assertTrue($gate->denies('delete', $event));
    }

    public function test_administrator_status_alone_does_not_grant_security_or_privileged_audit_access(): void
    {
        $administrator = User::factory()->administrator()->create();
        $target = User::factory()->create();
        $event = app(AuditEventRecorder::class)->record(
            AuditAction::AdministratorBootstrapCompleted,
            AuditActor::externalOperator('operator:primary'),
            AuditSubject::user($target),
            [
                'administrator_count_before' => 0,
                'bootstrap_marker_previously_set' => false,
                'configured_target_match' => true,
                'environment_category' => 'production',
                'outcome' => 'completed',
                'previous_privilege_state' => 'ordinary',
                'resulting_privilege_state' => 'administrator',
            ],
        );

        $this->assertTrue(Gate::forUser($administrator)->denies('view', $event));
    }

    private function moderationEvent(?User $administrator = null): AuditEvent
    {
        $administrator ??= User::factory()->administrator()->create();

        return app(AuditEventRecorder::class)->record(
            AuditAction::CatalogueProposalApproved,
            AuditActor::administrator($administrator),
            AuditSubject::resource(AuditSubjectType::CatalogueProposal, 'proposal:123'),
            ['decision_code' => 'approved_as_submitted', 'outcome' => 'approved'],
        );
    }
}
