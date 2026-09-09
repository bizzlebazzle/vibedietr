<?php

namespace App\Domain\Catalogue;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\CatalogueDuplicateCandidate;
use App\Models\CatalogueItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogueCandidateRecorder
{
    public function record(int $a, int $b, CatalogueDuplicateEvidence $evidence, ?User $submitter = null, ?string $explanation = null): CatalogueDuplicateCandidate
    {
        if ($a === $b || $a < 1 || $b < 1 || $evidence === CatalogueDuplicateEvidence::FuzzySuggestion) {
            throw ValidationException::withMessages(['candidate' => 'A candidate requires two different identities and deterministic evidence.']);
        }
        [$first, $second] = [min($a, $b), max($a, $b)];

        return DB::transaction(function () use ($first, $second, $evidence, $submitter, $explanation): CatalogueDuplicateCandidate {
            $items = CatalogueItem::query()->whereIn('id', [$first, $second])->orderBy('id')->lockForUpdate()->get();
            abort_unless($items->count() === 2, 404);
            $candidate = CatalogueDuplicateCandidate::query()->where('first_catalogue_item_id', $first)->where('second_catalogue_item_id', $second)->lockForUpdate()->first();
            if ($candidate !== null) {
                return $candidate->refresh();
            }
            $candidate = CatalogueDuplicateCandidate::query()->forceCreate([
                'first_catalogue_item_id' => $first, 'second_catalogue_item_id' => $second,
                'status' => CatalogueDuplicateCandidateStatus::PendingReview, 'evidence' => $evidence,
                'submitted_by_user_id' => $submitter?->getKey(),
                'distinction_explanation' => CatalogueName::optional($explanation, 500),
            ]);
            app(AuditEventRecorder::class)->record(AuditAction::CatalogueCandidateCreated, AuditActor::system(),
                AuditSubject::resource(AuditSubjectType::CatalogueItem, $first),
                ['candidate_id' => (string) $candidate->getKey(), 'outcome' => 'completed']);

            return $candidate->refresh();
        }, 3);
    }
}
