<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\JournalManifest;
use App\Domain\AccountDeletion\JournalUnavailable;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use Illuminate\Support\Str;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class JournalManifestTest extends TestCase
{
    use UsesErasureJournal;

    public function test_large_manifests_are_bounded_complete_and_retry_safe(): void
    {
        $resources = ['request_'.Str::ulid()];
        for ($i = 0; $i < 1001; $i++) {
            $resources[] = 'object_'.hash('sha256', (string) $i);
        }
        $intent = ['account_generation' => (string) Str::ulid(), 'installation' => config('erasure.installation'),
            'correlation_id' => (string) Str::ulid(), 'deadline' => '2026-11-17T12:00:00Z',
            'state' => 'requested', 'policy' => 'DEC-012/DEC-013/DEC-014/DEC-021/DEC-022', 'resources' => $resources];
        $operation = (string) Str::ulid();
        $manifest = app(JournalManifest::class);
        $checkpoint = $manifest->append($operation, $intent);
        $this->assertSame(4, $checkpoint->sequence);
        $this->assertEquals($checkpoint, $manifest->append($operation, $intent));
        $entries = app(ProtectedErasureJournal::class)->read($checkpoint);
        $root = $entries[array_key_last($entries)];
        $this->assertSame($resources, $manifest->resources($root, $entries));
        $this->expectException(JournalUnavailable::class);
        $manifest->resources($root, array_slice($entries, 1));
    }

    public function test_an_empty_generation_requires_an_exact_matching_genesis_checkpoint(): void
    {
        $journal = app(ProtectedErasureJournal::class);
        $checkpoint = $journal->checkpoint();
        $this->assertSame(0, $checkpoint->sequence);
        $this->assertSame([], $journal->read($checkpoint));
    }
}
