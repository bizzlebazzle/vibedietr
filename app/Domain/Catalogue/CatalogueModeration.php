<?php

namespace App\Domain\Catalogue;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\CatalogueDuplicateCandidate;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemAlias;
use App\Models\CatalogueModerationDecision;
use App\Models\CatalogueReferenceMove;
use App\Models\Recipe;
use App\Models\RecipeIngredientLineMatch;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CatalogueModeration
{
    public const MAX_REFERENCES = 500;

    public function __construct(
        private readonly CatalogueModerationAuthorization $authorization,
        private readonly AuditEventRecorder $audit,
    ) {}

    /** @param array<string, mixed> $review */
    public function approvePending(int $id, User $actor, Session $session, array $review): CatalogueModerationDecision
    {
        return $this->transaction($actor, $session, function () use ($id, $actor, $review) {
            $item = $this->pending($id, $review);
            if (CatalogueDuplicateCandidate::query()->where('status', CatalogueDuplicateCandidateStatus::ConfirmedDuplicate)
                ->where(fn ($q) => $q->where('first_catalogue_item_id', $id)->orWhere('second_catalogue_item_id', $id))->exists()) {
                $this->conflict('Resolve the contradictory duplicate decision before approval.');
            }
            $evidence = $this->itemEvidence($item);
            $item->forceFill(['status' => CatalogueItemStatus::Approved, 'moderation_revision' => $item->moderation_revision + 1])->save();

            return $this->decision('approve', $item, $actor, $review, $evidence);
        });
    }

    /** @param array<string, mixed> $review */
    public function rejectPending(int $id, User $actor, Session $session, array $review, ?int $replacementId = null): CatalogueModerationDecision
    {
        return $this->transaction($actor, $session, function () use ($id, $actor, $review, $replacementId) {
            // The target is explicitly a suggestion; no recipe reference is changed.
            $items = CatalogueItem::query()->whereIn('id', array_filter([$id, $replacementId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $item = $this->pending($id, $review);
            if ($replacementId !== null && ($replacementId === $id || $items->get($replacementId)?->status !== CatalogueItemStatus::Approved)) {
                $this->conflict('Choose a current approved replacement.');
            }
            if (($review['reason_code'] ?? null) === 'duplicate' && $replacementId === null) {
                $this->conflict('Duplicate rejection requires an explicit approved replacement suggestion.');
            }
            $evidence = $this->itemEvidence($item) + ['suggested_replacement_id' => $replacementId];
            $item->forceFill(['status' => CatalogueItemStatus::Rejected, 'suggested_replacement_catalogue_item_id' => $replacementId, 'moderation_revision' => $item->moderation_revision + 1])->save();

            return $this->decision('reject', $item, $actor, $review, $evidence);
        });
    }

    /** @param array<string, mixed> $review */
    public function markCandidateDistinct(int $id, User $actor, Session $session, array $review): CatalogueModerationDecision
    {
        return $this->candidateOutcome($id, $actor, $session, $review, CatalogueDuplicateCandidateStatus::ConfirmedDistinct, 'distinct');
    }

    /** @param array<string, mixed> $review */
    public function dismissCandidate(int $id, User $actor, Session $session, array $review): CatalogueModerationDecision
    {
        return $this->candidateOutcome($id, $actor, $session, $review, CatalogueDuplicateCandidateStatus::Dismissed, 'dismiss');
    }

    /** @param array<string, mixed> $review */
    public function confirmDuplicate(int $id, int $canonicalId, User $actor, Session $session, array $review): CatalogueModerationDecision
    {
        return $this->transaction($actor, $session, function () use ($id, $canonicalId, $actor, $review) {
            [$candidate, $first, $second] = $this->pair($id, $review, CatalogueDuplicateCandidateStatus::PendingReview);
            $this->approvedManualPair($first, $second);
            if (! in_array($canonicalId, [$first->id, $second->id], true)) {
                $this->conflict('Explicitly choose a canonical identity from this pair.');
            }
            Validator::make($review, ['identity_reviewed' => ['required', 'accepted']])->validate();
            $candidate->forceFill(['status' => CatalogueDuplicateCandidateStatus::ConfirmedDuplicate,
                'canonical_catalogue_item_id' => $canonicalId, 'moderation_revision' => $candidate->moderation_revision + 1])->save();

            return $this->decision('duplicate', $first, $actor, $review, $this->pairEvidence($first, $second), $candidate, $canonicalId);
        });
    }

    /** @param array<string, mixed> $review */
    public function mergeApproved(int $id, int $canonicalId, User $actor, Session $session, array $review): CatalogueModerationDecision
    {
        return $this->transaction($actor, $session, function () use ($id, $canonicalId, $actor, $review) {
            [$candidate, $first, $second] = $this->pair($id, $review, CatalogueDuplicateCandidateStatus::ConfirmedDuplicate);
            $this->approvedManualPair($first, $second);
            if ((int) $candidate->canonical_catalogue_item_id !== $canonicalId || ! in_array($canonicalId, [$first->id, $second->id], true)) {
                $this->conflict('The canonical choice differs from the reviewed duplicate decision.');
            }
            Validator::make($review, ['confirm_merge' => ['required', 'accepted'], 'alias_ids' => ['sometimes', 'array', 'max:20'], 'alias_ids.*' => ['integer', 'distinct'], 'exclude_primary_alias' => ['sometimes', 'boolean']])->validate();
            $confirmation = CatalogueModerationDecision::query()->where('candidate_id', $id)->where('action', 'duplicate')->latest('id')->lockForUpdate()->firstOrFail();
            if ($confirmation->evidence !== $this->pairEvidence($first, $second)) {
                $this->conflict('Catalogue versions changed after duplicate confirmation. Review the pair again.');
            }
            $canonical = $canonicalId === $first->id ? $first : $second;
            $source = $canonicalId === $first->id ? $second : $first;
            $redirects = CatalogueItem::query()->where('canonical_catalogue_item_id', $source->id)->orderBy('id')->limit(self::MAX_REFERENCES + 1)->lockForUpdate()->get();
            if ($redirects->count() > self::MAX_REFERENCES) {
                $this->conflict('This merge exceeds the synchronous redirect limit and requires maintenance review.');
            }
            $matches = $this->editableMatches($source->id);
            $aliases = $source->aliases()->whereNull('disabled_at')->whereIn('id', $review['alias_ids'] ?? [])->get();
            if ($aliases->count() !== count($review['alias_ids'] ?? [])) {
                $this->conflict('An explicitly selected alias is no longer available.');
            }
            $aliasNames = $aliases->pluck('alias')->all();
            if (! ($review['exclude_primary_alias'] ?? false)) {
                $aliasNames[] = $source->currentVersion->name;
            }
            $evidence = $this->pairEvidence($first, $second) + [
                'source_version_id' => $source->current_catalogue_item_version_id,
                'canonical_version_id' => $canonical->current_catalogue_item_version_id,
                'source_revision_after' => $source->moderation_revision + 1,
                'canonical_revision_after' => $canonical->moderation_revision + 1,
                'confirmation_id' => $confirmation->id,
            ];
            $decision = $this->decision('merge', $source, $actor, $review, $evidence, $candidate, $canonicalId);
            foreach ($matches as $match) {
                $previous = $match->catalogue_item_version_id;
                $match->forceFill(['catalogue_item_version_id' => $canonical->current_catalogue_item_version_id])->save();
                $this->move($decision, $actor, 'recipe_match', $match->id, $source->id, $canonicalId, $previous, $match->catalogue_item_version_id, $match->change_marker);
            }
            foreach ($redirects as $redirect) {
                if ($redirect->status !== CatalogueItemStatus::Merged || $redirect->id === $canonicalId) {
                    $this->conflict('Invalid redirect graph requires review.');
                }
                $redirect->forceFill(['canonical_catalogue_item_id' => $canonicalId, 'moderation_revision' => $redirect->moderation_revision + 1])->save();
                $this->move($decision, $actor, 'redirect', $redirect->id, $source->id, $canonicalId);
            }
            foreach (array_unique($aliasNames) as $name) {
                $normalized = CatalogueName::normalize($name);
                $existing = $canonical->aliases()->where('normalized_alias', $normalized)->lockForUpdate()->first();
                if ($existing !== null) {
                    if ($existing->disabled_at !== null) {
                        $this->conflict('A selected alias has prior correction history. Exclude it and review separately.');
                    }

                    continue;
                }
                CatalogueItemAlias::query()->forceCreate(['catalogue_item_id' => $canonicalId, 'alias' => $name,
                    'normalized_alias' => $normalized, 'approved_at' => now()->utc(), 'merge_decision_id' => $decision->id]);
            }
            $source->forceFill(['status' => CatalogueItemStatus::Merged, 'canonical_catalogue_item_id' => $canonicalId,
                'moderation_revision' => $source->moderation_revision + 1])->save();
            $canonical->forceFill(['moderation_revision' => $canonical->moderation_revision + 1])->save();
            $candidate->forceFill(['moderation_revision' => $candidate->moderation_revision + 1])->save();

            return $decision;
        });
    }

    /** @param array<string, mixed> $review */
    public function correctDecision(string $id, User $actor, Session $session, array $review): CatalogueModerationDecision
    {
        return $this->transaction($actor, $session, function () use ($id, $actor, $review) {
            Validator::make($review, ['confirm_correction' => ['required', 'accepted']])->validate();
            $original = CatalogueModerationDecision::query()->lockForUpdate()->findOrFail($id);
            if (CatalogueModerationDecision::query()->where('corrects_decision_id', $id)->lockForUpdate()->exists() || $original->action === 'correct') {
                $this->conflict('This decision has already been corrected. Review its later history.');
            }
            $items = CatalogueItem::query()->whereIn('id', array_filter([$original->catalogue_item_id, $original->canonical_catalogue_item_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $items->get($original->catalogue_item_id);
            $evidence = ['original_decision_id' => $id];
            $candidate = $original->candidate_id === null ? null : CatalogueDuplicateCandidate::query()->lockForUpdate()->findOrFail($original->candidate_id);
            if ($original->action === 'merge') {
                $canonical = $items->get($original->canonical_catalogue_item_id);
                if ($source->status !== CatalogueItemStatus::Merged || $canonical->status !== CatalogueItemStatus::Approved
                    || (int) $source->canonical_catalogue_item_id !== $canonical->id
                    || $source->current_catalogue_item_version_id !== $original->evidence['source_version_id']
                    || $canonical->current_catalogue_item_version_id !== $original->evidence['canonical_version_id']
                    || (int) $source->moderation_revision !== $original->evidence['source_revision_after']
                    || (int) $canonical->moderation_revision !== $original->evidence['canonical_revision_after']) {
                    $this->conflict('Subsequent catalogue changes require a separate reviewed correction; this merge cannot be restored automatically.');
                }
                $moves = CatalogueReferenceMove::query()->where('decision_id', $id)->orderBy('id')->lockForUpdate()->get();
                $restorable = [];
                $skipped = 0;
                foreach ($moves as $move) {
                    if ($move->reference_type === 'redirect') {
                        $redirect = CatalogueItem::query()->lockForUpdate()->findOrFail($move->reference_id);
                        if ($redirect->status !== CatalogueItemStatus::Merged || (int) $redirect->canonical_catalogue_item_id !== $canonical->id) {
                            $this->conflict('Redirect history changed and requires review.');
                        }
                        $restorable[] = [$move, $redirect];

                        continue;
                    }
                    $match = RecipeIngredientLineMatch::query()->find($move->reference_id);
                    $recipe = $match?->ingredientLine?->recipe()->lockForUpdate()->first();
                    $match = $match === null ? null : RecipeIngredientLineMatch::query()->lockForUpdate()->find($match->id);
                    if ($match === null || $recipe === null || ! $this->editable($recipe)
                        || $match->change_marker !== $move->change_marker
                        || $match->catalogue_item_version_id !== $move->new_version_id) {
                        $skipped++;

                        continue;
                    }
                    $restorable[] = [$move, $match];
                }
                if ($skipped > 0 && ! filter_var($review['preserve_later_choices'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $this->conflict('Some references changed or became historical. Explicitly preserve later choices before correcting the remaining safe references.');
                }
                $evidence += ['restored_count' => count($restorable), 'preserved_count' => $skipped];
                $correction = $this->decision('correct', $source, $actor, $review, $evidence, $candidate, $canonical->id, $id);
                foreach ($restorable as [$move, $record]) {
                    if ($move->reference_type === 'recipe_match') {
                        $record->forceFill(['catalogue_item_version_id' => $move->previous_version_id])->save();
                        $this->move($correction, $actor, 'recipe_match', $record->id, $move->new_item_id, $move->previous_item_id,
                            $move->new_version_id, $move->previous_version_id, $record->change_marker);
                    } else {
                        $record->forceFill(['canonical_catalogue_item_id' => $source->id, 'moderation_revision' => $record->moderation_revision + 1])->save();
                        $this->move($correction, $actor, 'redirect', $record->id, $canonical->id, $source->id);
                    }
                }
                CatalogueItemAlias::query()->where('merge_decision_id', $id)->update(['disabled_at' => now()->utc()]);
                $source->forceFill(['status' => CatalogueItemStatus::Approved, 'canonical_catalogue_item_id' => null, 'moderation_revision' => $source->moderation_revision + 1])->save();
                $canonical->forceFill(['moderation_revision' => $canonical->moderation_revision + 1])->save();
            } elseif (in_array($original->action, ['distinct', 'dismiss', 'duplicate'], true)) {
                $latest = CatalogueModerationDecision::query()->where('candidate_id', $candidate->id)->latest('id')->lockForUpdate()->first();
                if ($latest->id !== $id) {
                    $this->conflict('Later candidate decisions require review first.');
                }
                $correction = $this->decision('correct', $source, $actor, $review, $evidence, $candidate, null, $id);
            } else {
                $expected = $original->action === 'approve' ? CatalogueItemStatus::Approved : CatalogueItemStatus::Rejected;
                if ($source->status !== $expected || $source->current_catalogue_item_version_id !== $original->evidence['version_id']
                    || (int) $source->moderation_revision !== $original->evidence['revision'] + 1) {
                    $this->conflict('The catalogue item changed after that decision.');
                }
                if ($original->action === 'approve' && (RecipeIngredientLineMatch::query()->whereHas('catalogueItemVersion', fn ($q) => $q->where('catalogue_item_id', $source->id))->exists()
                    || CatalogueItem::query()->where('canonical_catalogue_item_id', $source->id)->exists())) {
                    $this->conflict('An approved identity in use needs a separately reviewed correction; it cannot safely become private automatically.');
                }
                $source->forceFill(['status' => CatalogueItemStatus::Pending, 'moderation_revision' => $source->moderation_revision + 1])->save();
                // Keep the original rejection recommendation as historical evidence.
                $correction = $this->decision('correct', $source, $actor, $review, $evidence, null, null, $id);
            }
            if ($candidate !== null) {
                $candidate->forceFill(['status' => CatalogueDuplicateCandidateStatus::PendingReview, 'canonical_catalogue_item_id' => null,
                    'moderation_revision' => $candidate->moderation_revision + 1])->save();
            }

            return $correction;
        });
    }

    /** @param array<string, mixed> $review */
    private function candidateOutcome(int $id, User $actor, Session $session, array $review, CatalogueDuplicateCandidateStatus $status, string $action): CatalogueModerationDecision
    {
        return $this->transaction($actor, $session, function () use ($id, $actor, $review, $status, $action) {
            [$candidate, $first, $second] = $this->pair($id, $review, CatalogueDuplicateCandidateStatus::PendingReview);
            $candidate->forceFill(['status' => $status, 'moderation_revision' => $candidate->moderation_revision + 1])->save();

            return $this->decision($action, $first, $actor, $review, $this->pairEvidence($first, $second), $candidate);
        });
    }

    private function transaction(User $actor, Session $session, Closure $action): CatalogueModerationDecision
    {
        $this->authorization->authorize($actor, $session, consume: false);
        try {
            return DB::transaction(function () use ($actor, $session, $action) {
                User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('moderate-catalogue');
                $this->authorization->authorize($actor, $session, consume: false);
                $result = $action();
                $this->authorization->authorize($actor, $session);

                return $result;
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) {
                $this->conflict('Another operation changed this work. Reload and review before retrying.');
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $review */
    private function pending(int $id, array $review): CatalogueItem
    {
        Validator::make($review, ['revision' => ['required', 'integer', 'min:0'], 'version_id' => ['required', 'string']])->validate();
        $item = CatalogueItem::query()->lockForUpdate()->findOrFail($id);
        if ($item->origin !== CatalogueItemOrigin::Manual || $item->status !== CatalogueItemStatus::Pending
            || (int) $item->moderation_revision !== (int) $review['revision'] || $item->current_catalogue_item_version_id !== $review['version_id']) {
            $this->conflict('This pending submission changed. Reload and review it again.');
        }

        return $item;
    }

    /** @param array<string, mixed> $review
     * @return array{CatalogueDuplicateCandidate, CatalogueItem, CatalogueItem}
     */
    private function pair(int $id, array $review, CatalogueDuplicateCandidateStatus $status): array
    {
        Validator::make($review, ['revision' => ['required', 'integer', 'min:0'], 'first_version_id' => ['required', 'string'], 'second_version_id' => ['required', 'string']])->validate();
        $candidate = CatalogueDuplicateCandidate::query()->findOrFail($id);
        $items = CatalogueItem::query()->whereIn('id', [$candidate->first_catalogue_item_id, $candidate->second_catalogue_item_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $candidate = CatalogueDuplicateCandidate::query()->lockForUpdate()->findOrFail($id);
        $first = $items->get($candidate->first_catalogue_item_id);
        $second = $items->get($candidate->second_catalogue_item_id);
        if ($candidate->status !== $status || (int) $candidate->moderation_revision !== (int) $review['revision']
            || $first->current_catalogue_item_version_id !== $review['first_version_id'] || $second->current_catalogue_item_version_id !== $review['second_version_id']) {
            $this->conflict('This candidate or its versions changed. Reload and review again.');
        }

        return [$candidate, $first, $second];
    }

    private function approvedManualPair(CatalogueItem $first, CatalogueItem $second): void
    {
        foreach ([$first, $second] as $item) {
            if ($item->status !== CatalogueItemStatus::Approved || $item->origin !== CatalogueItemOrigin::Manual || $item->barcode !== null || $item->currentVersion === null || trim((string) $item->currentVersion->name) === '') {
                $this->conflict('Only two approved non-barcode manual identities may be merged.');
            }
        }
        $firstBases = $first->currentVersion->nutrientValues()->toBase()->distinct()->pluck('basis')->all();
        $secondBases = $second->currentVersion->nutrientValues()->toBase()->distinct()->pluck('basis')->all();
        if ($firstBases !== [] && $secondBases !== [] && array_intersect($firstBases, $secondBases) === []) {
            $this->conflict('Nutrition bases have no common reviewed basis. Resolve this evidence before merging.');
        }
        foreach (['manual_food_classification', 'brand', 'manufacturer', 'food_form', 'preparation', 'treatment', 'composition'] as $field) {
            $a = $first->currentVersion->getRawOriginal($field);
            $b = $second->currentVersion->getRawOriginal($field);
            if ($a !== null && $b !== null && CatalogueName::normalize($a) !== CatalogueName::normalize($b)) {
                $this->conflict('Core identity evidence contradicts this merge. Resolve factual corrections separately.');
            }
        }
    }

    private function editable(Recipe $recipe): bool
    {
        return $recipe->getRawOriginal('lifecycle') === 'draft' || ($recipe->isFinalized() && $recipe->activeRevision()->exists());
    }

    /** @return Collection<int, RecipeIngredientLineMatch> */
    private function editableMatches(int $sourceId): Collection
    {
        $matches = RecipeIngredientLineMatch::query()->whereHas('catalogueItemVersion', fn ($q) => $q->where('catalogue_item_id', $sourceId))
            ->orderBy('recipe_ingredient_line_id')->limit(self::MAX_REFERENCES + 1)->lockForUpdate()->get();
        if ($matches->count() > self::MAX_REFERENCES) {
            $this->conflict('This merge exceeds the synchronous reference limit and requires maintenance review.');
        }
        $result = collect();
        foreach ($matches as $match) {
            $recipe = $match->ingredientLine->recipe()->lockForUpdate()->firstOrFail();
            $current = RecipeIngredientLineMatch::query()->lockForUpdate()->find($match->id);
            if ($current !== null && $this->editable($recipe) && (int) $current->catalogueItemVersion->catalogue_item_id === $sourceId) {
                $result->push($current);
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function itemEvidence(CatalogueItem $item): array
    {
        return ['version_id' => $item->current_catalogue_item_version_id, 'revision' => (int) $item->moderation_revision];
    }

    /** @return array<string, mixed> */
    private function pairEvidence(CatalogueItem $first, CatalogueItem $second): array
    {
        return ['first_version_id' => $first->current_catalogue_item_version_id, 'second_version_id' => $second->current_catalogue_item_version_id];
    }

    /** @param array<string, mixed> $review
     * @param  array<string, mixed>  $evidence
     */
    private function decision(string $action, CatalogueItem $item, User $actor, array $review, array $evidence, ?CatalogueDuplicateCandidate $candidate = null, ?int $canonicalId = null, ?string $corrects = null): CatalogueModerationDecision
    {
        Validator::make($review, ['reason_code' => ['required', Rule::in(['reviewed', 'duplicate', 'distinct', 'insufficient_evidence', 'incorrect_decision'])], 'note' => ['nullable', 'string', 'max:500']])->validate();
        $id = strtolower((string) Str::ulid());
        $auditAction = match ($action) {
            'approve' => AuditAction::CataloguePendingApproved, 'reject' => AuditAction::CataloguePendingRejected,
            'distinct' => AuditAction::CatalogueCandidateDistinct, 'dismiss' => AuditAction::CatalogueCandidateDismissed,
            'duplicate' => AuditAction::CatalogueCandidateDuplicate, 'merge' => AuditAction::CatalogueMergeApplied,
            'correct' => AuditAction::CatalogueDecisionCorrected,
            default => throw new \LogicException('Unsupported moderation action.'),
        };
        $event = $this->audit->record($auditAction, AuditActor::administrator($actor), AuditSubject::resource(AuditSubjectType::CatalogueItem, $item->id),
            ['decision_id' => $id, 'reason_code' => $review['reason_code'], 'outcome' => 'completed'], $id);

        return CatalogueModerationDecision::query()->forceCreate([
            'id' => $id, 'action' => $action, 'catalogue_item_id' => $item->id, 'candidate_id' => $candidate?->id,
            'canonical_catalogue_item_id' => $canonicalId, 'corrects_decision_id' => $corrects,
            'actor_identity_id' => $event->actor_identity_id, 'reason_code' => $review['reason_code'],
            'note' => CatalogueName::optional($review['note'] ?? null, 500), 'evidence' => $evidence,
        ]);
    }

    private function move(CatalogueModerationDecision $decision, User $actor, string $type, int $referenceId, int $previousItem, int $newItem, ?string $previousVersion = null, ?string $newVersion = null, ?string $marker = null): void
    {
        $move = CatalogueReferenceMove::query()->forceCreate(['decision_id' => $decision->id, 'reference_type' => $type,
            'reference_id' => $referenceId, 'previous_item_id' => $previousItem, 'new_item_id' => $newItem,
            'previous_version_id' => $previousVersion, 'new_version_id' => $newVersion, 'change_marker' => $marker]);
        $this->audit->record(AuditAction::CatalogueReferenceMoved, AuditActor::administrator($actor),
            AuditSubject::resource(AuditSubjectType::CatalogueItem, $decision->catalogue_item_id),
            ['decision_id' => $decision->id, 'move_id' => $move->id, 'outcome' => 'completed'], $decision->id);
    }

    private function conflict(string $message): never
    {
        throw ValidationException::withMessages(['moderation' => $message]);
    }
}
