<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\CatalogueChangeProposalType;
use App\Domain\Catalogue\CatalogueCorrectionModeration;
use App\Domain\Catalogue\CatalogueProviderRefreshRequester;
use App\Domain\Catalogue\CatalogueProviderRefreshState;
use App\Models\CatalogueProviderRefresh;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class CatalogueProviderRefreshController extends Controller
{
    public function store(int $item, CatalogueProviderRefreshRequester $requester): RedirectResponse
    {
        Gate::authorize('moderate-catalogue');
        $refresh = $requester->request($item);

        return redirect()->route('admin.catalogue.provider-refresh', $refresh)
            ->with('status', 'OpenFoodFacts refresh queued. The current catalogue version remains unchanged while it runs.');
    }

    public function show(string $refresh, CatalogueCorrectionModeration $moderation): View
    {
        Gate::authorize('moderate-catalogue');
        $refresh = CatalogueProviderRefresh::query()
            ->with(['catalogueItem.currentVersion', 'baseVersion', 'proposal.changes'])
            ->findOrFail($refresh);
        if ($refresh->proposal !== null) {
            abort_unless($refresh->proposal->proposal_type === CatalogueChangeProposalType::ProviderRefresh, 404);
        }

        $review = $refresh->proposal === null ? null : $moderation->review($refresh->proposal);

        return view('admin.catalogue.provider-refresh', compact('refresh', 'review'));
    }

    public function accept(Request $request, string $refresh, CatalogueCorrectionModeration $moderation): RedirectResponse
    {
        $refresh = $this->staged($refresh);
        $moderation->accept($refresh->proposal->id, $request->user(), $request->session(), $request->boolean('stale_reviewed'));

        return redirect()->route('admin.catalogue.provider-refresh', $refresh)
            ->with('status', 'Provider refresh accepted as one new catalogue version.');
    }

    public function reject(Request $request, string $refresh, CatalogueCorrectionModeration $moderation): RedirectResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'in:reviewed,insufficient_evidence'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $refresh = $this->staged($refresh);
        $moderation->reject($refresh->proposal->id, $request->user(), $request->session(), $data['reason_code'], $data['note'] ?? null);

        return redirect()->route('admin.catalogue.provider-refresh', $refresh)
            ->with('status', 'Provider refresh rejected; the current catalogue version is unchanged.');
    }

    private function staged(string $id): CatalogueProviderRefresh
    {
        Gate::authorize('moderate-catalogue');
        $refresh = CatalogueProviderRefresh::query()->with('proposal')->findOrFail($id);
        abort_unless(
            $refresh->state === CatalogueProviderRefreshState::Staged
            && $refresh->proposal?->proposal_type === CatalogueChangeProposalType::ProviderRefresh,
            409,
        );

        return $refresh;
    }
}
