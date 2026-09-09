<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\CatalogueModeration;
use App\Domain\Catalogue\CatalogueModerationQueue;
use App\Models\CatalogueDuplicateCandidate;
use App\Models\CatalogueItem;
use App\Models\CatalogueModerationDecision;
use App\Models\CatalogueReferenceMove;
use App\Models\RecipeIngredientLineMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class CatalogueModerationController extends Controller
{
    public function index(Request $request, CatalogueModerationQueue $queue): View
    {
        $filters = $request->validate(['type' => ['sometimes', 'string'], 'state' => ['nullable', 'string']]);
        $type = $filters['type'] ?? 'manual_submission';
        $state = $filters['state'] ?? '';
        $rows = $queue->paginate($request->user(), $type, $state);

        return view('admin.catalogue.index', compact('rows', 'type', 'state'));
    }

    public function submission(int $item): View
    {
        Gate::authorize('moderate-catalogue');
        $record = CatalogueItem::query()->with(['currentVersion.nutrientValues', 'aliases', 'submitter'])->findOrFail($item);
        abort_unless($record->origin->value === 'manual', 404);
        $history = CatalogueModerationDecision::query()->where('catalogue_item_id', $item)->latest('id')->paginate(15);
        $candidates = CatalogueDuplicateCandidate::query()->where(fn ($q) => $q->where('first_catalogue_item_id', $item)->orWhere('second_catalogue_item_id', $item))->orderBy('id')->paginate(15, ['*'], 'candidates');

        return view('admin.catalogue.submission', compact('record', 'history', 'candidates'));
    }

    public function candidate(int $candidate): View
    {
        Gate::authorize('moderate-catalogue');
        $record = CatalogueDuplicateCandidate::query()->with(['firstItem.currentVersion.nutrientValues', 'secondItem.currentVersion.nutrientValues', 'firstItem.aliases', 'secondItem.aliases'])->findOrFail($candidate);
        $history = CatalogueModerationDecision::query()->where('candidate_id', $candidate)->latest('id')->paginate(15);
        $counts = [];
        foreach ([$record->firstItem, $record->secondItem] as $item) {
            $counts[$item->id] = RecipeIngredientLineMatch::query()
                ->whereHas('catalogueItemVersion', fn ($q) => $q->where('catalogue_item_id', $item->id))
                ->whereHas('ingredientLine.recipe', fn ($q) => $q->where('lifecycle', 'draft')->orWhere(fn ($r) => $r->where('lifecycle', 'finalized')->whereHas('activeRevision')))->count();
        }

        return view('admin.catalogue.candidate', compact('record', 'history', 'counts'));
    }

    public function decision(string $decision): View
    {
        Gate::authorize('moderate-catalogue');
        $record = CatalogueModerationDecision::query()->findOrFail($decision);
        $moves = CatalogueReferenceMove::query()->where('decision_id', $decision)->selectRaw('reference_type, count(*) as total')->groupBy('reference_type')->get();
        $correction = CatalogueModerationDecision::query()->where('corrects_decision_id', $decision)->first();

        return view('admin.catalogue.decision', compact('record', 'moves', 'correction'));
    }

    public function approve(Request $request, int $item, CatalogueModeration $service): RedirectResponse
    {
        $service->approvePending($item, $request->user(), $request->session(), $this->review($request));

        return redirect()->route('admin.catalogue.submission', $item)->with('status', 'Submission approved.');
    }

    public function reject(Request $request, int $item, CatalogueModeration $service): RedirectResponse
    {
        $data = $request->validate(['replacement_id' => ['nullable', 'integer', 'min:1']]);
        $replacement = isset($data['replacement_id']) ? (int) $data['replacement_id'] : null;
        $service->rejectPending($item, $request->user(), $request->session(), $this->review($request), $replacement);

        return redirect()->route('admin.catalogue.submission', $item)->with('status', 'Submission rejected. Existing recipe matches are unchanged.');
    }

    public function distinct(Request $request, int $candidate, CatalogueModeration $service): RedirectResponse
    {
        $service->markCandidateDistinct($candidate, $request->user(), $request->session(), $this->review($request));

        return redirect()->route('admin.catalogue.candidate', $candidate)->with('status', 'Candidate confirmed distinct.');
    }

    public function dismiss(Request $request, int $candidate, CatalogueModeration $service): RedirectResponse
    {
        $service->dismissCandidate($candidate, $request->user(), $request->session(), $this->review($request));

        return redirect()->route('admin.catalogue.candidate', $candidate)->with('status', 'Candidate dismissed without an identity determination.');
    }

    public function duplicate(Request $request, int $candidate, CatalogueModeration $service): RedirectResponse
    {
        $data = $request->validate(['canonical_id' => ['required', 'integer', 'min:1']]);
        $service->confirmDuplicate($candidate, (int) $data['canonical_id'], $request->user(), $request->session(), $this->review($request));

        return redirect()->route('admin.catalogue.candidate', $candidate)->with('status', 'Duplicate confirmed. Review and confirm merge application separately.');
    }

    public function merge(Request $request, int $candidate, CatalogueModeration $service): RedirectResponse
    {
        $data = $request->validate(['canonical_id' => ['required', 'integer', 'min:1']]);
        $decision = $service->mergeApproved($candidate, (int) $data['canonical_id'], $request->user(), $request->session(), $this->review($request));

        return redirect()->route('admin.catalogue.decision', $decision)->with('status', 'Merge applied. Historical versions and snapshots remain unchanged.');
    }

    public function correct(Request $request, string $decision, CatalogueModeration $service): RedirectResponse
    {
        $correction = $service->correctDecision($decision, $request->user(), $request->session(), $this->review($request));

        return redirect()->route('admin.catalogue.decision', $correction)->with('status', 'Correction recorded. The original decision remains in history.');
    }

    /** @return array<string, mixed> */
    private function review(Request $request): array
    {
        return $request->only(['revision', 'version_id', 'first_version_id', 'second_version_id', 'reason_code', 'note', 'identity_reviewed', 'confirm_merge', 'exclude_primary_alias', 'alias_ids', 'confirm_correction', 'preserve_later_choices']);
    }
}
