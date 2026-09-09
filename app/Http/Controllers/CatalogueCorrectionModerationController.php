<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\CatalogueCorrectionModeration;
use App\Models\CatalogueCorrectionProposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class CatalogueCorrectionModerationController extends Controller
{
    public function show(string $proposal, CatalogueCorrectionModeration $service): View
    {
        Gate::authorize('moderate-catalogue');
        $proposal = CatalogueCorrectionProposal::query()->with(['changes', 'catalogueItem.currentVersion', 'baseVersion'])->findOrFail($proposal);
        $review = $service->review($proposal);

        return view('admin.catalogue.correction', compact('proposal', 'review'));
    }

    public function accept(Request $request, string $proposal, CatalogueCorrectionModeration $service): RedirectResponse
    {
        $service->accept($proposal, $request->user(), $request->session(), $request->boolean('stale_reviewed'));

        return redirect()->route('admin.catalogue.correction', $proposal)->with('status', 'Correction accepted as one new catalogue version.');
    }

    public function reject(Request $request, string $proposal, CatalogueCorrectionModeration $service): RedirectResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'in:reviewed,insufficient_evidence'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $service->reject($proposal, $request->user(), $request->session(), $data['reason_code'], $data['note'] ?? null);

        return redirect()->route('admin.catalogue.correction', $proposal)->with('status', 'Correction rejected; the current catalogue version is unchanged.');
    }
}
