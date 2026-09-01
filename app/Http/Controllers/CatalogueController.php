<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\CatalogueReadQuery;
use App\Models\User;
use Illuminate\Http\Request;
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

        return view('catalogue.show', ['item' => $catalogue->project($record)]);
    }
}
