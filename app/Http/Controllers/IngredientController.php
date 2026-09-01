<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\CatalogueReadQuery;
use App\Domain\Ingredients\IngredientWriteNormalizer;
use App\Http\Requests\StoreIngredientRequest;
use App\Http\Requests\UpdateIngredientRequest;
use App\Models\Ingredient;
use Symfony\Component\HttpFoundation\Response;

class IngredientController extends Controller
{
    public function index()
    {
        if (config('catalogue.read_cutover')) {
            return redirect()->route(
                'catalogue.index',
                request()->only(['q', 'page', 'legacyPage']),
                Response::HTTP_FOUND,
            );
        }

        return view('ingredients.index');
    }

    public function create()
    {
        // Blade view will mount the Livewire form component
        return view('ingredients.create');
    }

    public function store(StoreIngredientRequest $request, IngredientWriteNormalizer $normalizer)
    {
        $this->authorize('create', Ingredient::class);

        $ingredient = new Ingredient($normalizer->normalize($request->validated()));
        $ingredient->user()->associate($request->user());
        $ingredient->save();

        return redirect()->route($this->readIndexRoute())->with('status', 'Ingredient created.');
    }

    public function show(Ingredient $ingredient, CatalogueReadQuery $catalogue)
    {
        if (config('catalogue.read_cutover')) {
            $mapping = $ingredient->catalogueMapping()->first();

            if ($mapping?->catalogue_item_id !== null) {
                $catalogue->findVisibleOrFail(
                    $mapping->catalogue_item_id,
                    request()->user(),
                );

                return redirect()->route('catalogue.show', [
                    'catalogueItem' => $mapping->catalogue_item_id,
                ], Response::HTTP_FOUND);
            }
        }

        $this->authorize('view', $ingredient);

        return view('ingredients.show', compact('ingredient'));
    }

    public function edit(Ingredient $ingredient)
    {
        $this->authorize('update', $ingredient);

        return view('ingredients.edit', compact('ingredient'));
    }

    public function update(
        UpdateIngredientRequest $request,
        Ingredient $ingredient,
        IngredientWriteNormalizer $normalizer,
    ) {
        $this->authorize('update', $ingredient);
        $ingredient->update($normalizer->normalize($request->validated()));

        return redirect()->route($this->readIndexRoute())->with('status', 'Ingredient updated.');
    }

    public function destroy(Ingredient $ingredient)
    {
        $this->authorize('delete', $ingredient);
        $ingredient->delete();

        return redirect()->route($this->readIndexRoute())->with('status', 'Ingredient deleted.');
    }

    private function readIndexRoute(): string
    {
        return config('catalogue.read_cutover') ? 'catalogue.index' : 'ingredients.index';
    }
}
