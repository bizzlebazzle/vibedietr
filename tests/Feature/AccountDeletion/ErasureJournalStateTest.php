<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\ErasureJournalState;
use App\Domain\AccountDeletion\JournalManifest;
use App\Domain\AccountDeletion\JournalUnavailable;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use Illuminate\Support\Str;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class ErasureJournalStateTest extends TestCase
{
    use UsesErasureJournal;

    public function test_current_independent_head_is_required_and_complete_manifests_expand(): void
    {
        $journal = app(ProtectedErasureJournal::class);
        $old = $journal->checkpoint();
        $intent = $this->intent();
        $intent['resources'] = [...$intent['resources'], ...array_fill(0, 401, 'object_'.str_repeat('a', 64))];
        $head = app(JournalManifest::class)->append((string) Str::ulid(), $intent);
        $state = app(ErasureJournalState::class);
        $this->assertSame($intent['resources'], $state->at($head)[0]['resources']);
        $this->expectException(JournalUnavailable::class);
        $state->at($old);
    }

    public function test_partial_waiver_manifest_is_not_covered_by_a_completed_requested_manifest(): void
    {
        $intent = $this->intent();
        $intent['resources'] = [...$intent['resources'], ...array_fill(0, 401, 'object_'.str_repeat('b', 64))];
        app(JournalManifest::class)->append((string) Str::ulid(), $intent);
        $part = $intent;
        $part['state'] = 'inventory';
        $part['resources'] = [$intent['resources'][0], 'manifest_'.hash('sha256', json_encode($intent['resources'], JSON_THROW_ON_ERROR)),
            'part_0000000000000000', 'transition_waived', ...array_slice($intent['resources'], 0, 400)];
        $head = app(ProtectedErasureJournal::class)->append((string) Str::ulid(), $part);
        $this->expectException(JournalUnavailable::class);
        app(ErasureJournalState::class)->at($head);
    }

    public function test_recovery_does_not_erase_the_original_clock_for_a_later_request(): void
    {
        $first = $this->intent();
        $recovered = [...$first, 'state' => 'recovered'];
        $next = [...$first, 'deadline' => '2026-12-17T12:00:00Z', 'resources' => ['request_'.Str::ulid()]];
        $latest = app(ErasureJournalState::class)->latest([$first, $recovered, $next]);
        $this->assertSame($next, $latest[$first['account_generation']]);
        $this->expectException(JournalUnavailable::class);
        app(ErasureJournalState::class)->latest([$first, [...$first, 'deadline' => '2026-12-17T12:00:00Z']]);
    }

    public function test_irreversible_under13_policy_survives_completion_and_cannot_recover(): void
    {
        $first = [...$this->intent(), 'state' => 'under13'];
        $completed = [...$first, 'state' => 'purged'];
        $state = app(ErasureJournalState::class);
        $latest = $state->latest([$first, $completed]);
        $this->assertTrue($latest[$first['account_generation']]['under13']);
        $this->assertContains('policy_under13_no_recovery', $latest[$first['account_generation']]['resources']);
        $this->expectException(JournalUnavailable::class);
        $state->latest([$first, [...$first, 'state' => 'recovered']]);
    }

    private function intent(): array
    {
        return ['account_generation' => (string) Str::ulid(), 'installation' => config('erasure.installation'),
            'correlation_id' => (string) Str::ulid(), 'deadline' => '2026-11-17T12:00:00Z',
            'state' => 'requested', 'policy' => 'DEC-012/DEC-013/DEC-014/DEC-021/DEC-022',
            'resources' => ['request_'.Str::ulid()]];
    }
}
