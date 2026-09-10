<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueReadQuery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CatalogueController extends Controller
{
    public function index(): View
    {
        abort_unless(config('catalogue.read_cutover'), 404);

        return view('catalogue.index');
    }

    public function show(Request $request, int $catalogueItem, CatalogueReadQuery $catalogue): View
    {
        abort_unless(config('catalogue.read_cutover'), 404);

        $user = $request->user();
        $record = $catalogue->findVisibleOrFail(
            $catalogueItem,
            $user instanceof User ? $user : null,
        );
        $canRequestProviderRefresh = $user instanceof User
            && Gate::forUser($user)->allows('moderate-catalogue')
            && $record->status === CatalogueItemStatus::Approved
            && $record->canonical_catalogue_item_id === null
            && $record->current_catalogue_item_version_id !== null
            && $record->source === CatalogueItemSource::OpenFoodFacts
            && $record->barcode !== null
            && $record->source_identifier !== null;

        return view('catalogue.show', [
            'item' => $catalogue->project($record),
            'canRequestProviderRefresh' => $canRequestProviderRefresh,
        ]);
    }
}
