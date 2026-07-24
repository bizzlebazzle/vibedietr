<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIngredientRequest;
use App\Http\Requests\UpdateIngredientRequest;
use App\Models\Ingredient;

class IngredientController extends Controller
{
    public function index()
    {
        // Blade view will mount the Livewire list/search component
        return view('ingredients.index');
    }

    public function create()
    {
        // Blade view will mount the Livewire form component
        return view('ingredients.create');
    }

    public function store(StoreIngredientRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = auth()->id();

        Ingredient::create($data);

        return redirect()->route('ingredients.index')->with('status', 'Ingredient created.');
    }

    public function show(Ingredient $ingredient)
    {
        $this->authorize('view', $ingredient);
        return view('ingredients.show', compact('ingredient'));
    }

    public function edit(Ingredient $ingredient)
    {
        $this->authorize('update', $ingredient);
        return view('ingredients.edit', compact('ingredient'));
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient)
    {
        $this->authorize('update', $ingredient);
        $ingredient->update($request->validated());

        return redirect()->route('ingredients.index')->with('status', 'Ingredient updated.');
    }

    public function destroy(Ingredient $ingredient)
    {
        $this->authorize('delete', $ingredient);
        $ingredient->delete();

        return redirect()->route('ingredients.index')->with('status', 'Ingredient deleted.');
    }
}
