<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\ErasureObjectFilter;
use App\Domain\AccountDeletion\JournalUnavailable;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class ErasureObjectFilterTest extends TestCase
{
    use UsesErasureJournal;

    public function test_sql_independent_filter_preserves_other_owners_and_honours_exact_clock(): void
    {
        config(['erasure.privacy_operator' => 'synthetic-object-operator', 'erasure.operator_environment' => 'testing']);
        $this->freezeSecond();
        $owner = (string) Str::ulid();
        $other = (string) Str::ulid();
        $intent = ['account_generation' => $owner, 'installation' => config('erasure.installation'),
            'correlation_id' => (string) Str::ulid(), 'deadline' => now()->addDay()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'state' => 'requested', 'policy' => 'DEC-012/DEC-013/DEC-014/DEC-021/DEC-022',
            'resources' => ['request_'.Str::ulid()]];
        $head = app(ProtectedErasureJournal::class)->append((string) Str::ulid(), $intent);
        $manifest = ['format' => 1, 'excluded_prefixes' => [], 'objects' => [
            'private-original' => ['owner_generation' => $owner, 'resource' => 'private-resource'],
            'independent-copy' => ['owner_generation' => $other, 'resource' => 'copy-resource'],
        ]];
        ksort($manifest['objects'], SORT_STRING);
        $filter = app(ErasureObjectFilter::class);
        $this->assertSame($manifest, $filter->filter($manifest, $head));
        $this->travelTo(CarbonImmutable::parse($intent['deadline']));
        $filtered = $filter->filter($manifest, $head);
        $this->assertSame(['independent-copy'], array_keys($filtered['objects']));
        $this->assertSame($manifest['objects']['independent-copy'], $filtered['objects']['independent-copy']);
    }

    public function test_missing_ownership_fails_closed_without_relying_on_sql(): void
    {
        config(['erasure.privacy_operator' => 'synthetic-object-operator', 'erasure.operator_environment' => 'testing']);
        $this->expectException(JournalUnavailable::class);
        app(ErasureObjectFilter::class)->filter(['format' => 1, 'objects' => ['unknown' => []]],
            app(ProtectedErasureJournal::class)->checkpoint());
    }
}
