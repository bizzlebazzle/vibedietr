<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\ManualCatalogueSubmissionCreator;
use App\Domain\Catalogue\ManualCatalogueSubmissionStatus;
use App\Http\Requests\StoreManualCatalogueSubmissionRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ManualCatalogueSubmissionController extends Controller
{
    public function create(): View
    {
        abort_unless(config('catalogue.read_cutover'), 404);

        return view('catalogue.manual.create', ['strongMatches' => [], 'input' => []]);
    }

    public function store(
        StoreManualCatalogueSubmissionRequest $request,
        ManualCatalogueSubmissionCreator $creator,
    ): Response|RedirectResponse {
        abort_unless(config('catalogue.read_cutover'), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $result = $creator->submit($user, $request->submissionData());

        if ($result->status === ManualCatalogueSubmissionStatus::ChoiceRequired) {
            return response()->view('catalogue.manual.create', [
                'input' => $request->safe()->except([
                    'duplicate_choice',
                    'duplicate_item_id',
                    'distinction_explanation',
                ]),
                'strongMatches' => array_map(
                    fn ($match): array => $match->toArray(),
                    $result->strongMatches,
                ),
            ], 422);
        }

        $message = $result->status === ManualCatalogueSubmissionStatus::Reused
            ? 'Existing approved catalogue food selected; no new submission was created.'
            : 'Manual food submitted. It remains private while awaiting moderation.';

        return redirect()->route('catalogue.show', $result->item)
            ->with('status', $message)
            ->with('fuzzySuggestions', array_map(
                fn ($match): array => $match->toArray(),
                $result->fuzzySuggestions,
            ));
    }
}
