<?php

namespace App\Livewire\Recipes;

use App\Domain\Recipes\RecipeInstructionWriter;
use App\Models\Recipe;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use Closure;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Instructions extends Component
{
    #[Locked]
    public int $recipeId;

    #[Locked]
    public ?int $editingStepId = null;

    #[Locked]
    public ?int $editingSectionId = null;

    public string $instructionText = '';

    public $sectionId = null;

    public string $sectionName = '';

    public function mount(Recipe $recipe): void
    {
        $this->authorize('update', $recipe);
        $this->recipeId = $recipe->getKey();
    }

    /** @return array<string, array<int, mixed>> */
    private function stepRules(): array
    {
        return [
            'instructionText' => [
                'required',
                'string',
                'max:10000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        $fail('The instruction step is required.');
                    }
                },
            ],
            'sectionId' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function sectionRules(): array
    {
        return [
            'sectionName' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        $fail('The section name is required.');
                    }
                },
            ],
        ];
    }

    public function saveStep(RecipeInstructionWriter $writer): void
    {
        $recipe = $this->ownedRecipe();
        $this->validate($this->stepRules());
        $sectionId = $this->sectionId === null || $this->sectionId === '' ? null : (int) $this->sectionId;

        if ($this->editingStepId === null) {
            $writer->appendStep($recipe, $this->instructionText, $sectionId);
            session()->flash('instruction-status', 'Instruction step added.');
        } else {
            $writer->updateStep($recipe, $this->editingStepId, $this->instructionText, $sectionId);
            session()->flash('instruction-status', 'Instruction step updated.');
        }

        $this->resetStepEditor();
    }

    public function editStep(int $stepId): void
    {
        $step = $this->ownedRecipe()->instructionSteps()->find($stepId);

        if (! $step instanceof RecipeInstructionStep) {
            abort(404);
        }

        $this->editingStepId = $step->getKey();
        $this->instructionText = $step->text;
        $this->sectionId = $step->section_id;
    }

    public function cancelStepEdit(): void
    {
        $this->resetStepEditor();
    }

    public function deleteStep(int $stepId, RecipeInstructionWriter $writer): void
    {
        $writer->deleteStep($this->ownedRecipe(), $stepId);
        $this->resetStepEditor();
        session()->flash('instruction-status', 'Instruction step removed.');
    }

    public function moveStepUp(int $stepId, RecipeInstructionWriter $writer): void
    {
        $this->moveStep($stepId, -1, $writer);
    }

    public function moveStepDown(int $stepId, RecipeInstructionWriter $writer): void
    {
        $this->moveStep($stepId, 1, $writer);
    }

    /** @param list<int> $stepIds */
    public function reorderSteps(array $stepIds, RecipeInstructionWriter $writer): void
    {
        Validator::make(
            ['stepIds' => $stepIds],
            ['stepIds' => ['array'], 'stepIds.*' => ['required', 'integer']]
        )->validate();
        $writer->reorderSteps($this->ownedRecipe(), $stepIds);
    }

    public function saveSection(RecipeInstructionWriter $writer): void
    {
        $recipe = $this->ownedRecipe();
        $this->validate($this->sectionRules());
        $name = trim($this->sectionName);

        if ($this->editingSectionId === null) {
            $writer->appendSection($recipe, $name);
            session()->flash('instruction-status', 'Instruction section added.');
        } else {
            $writer->updateSection($recipe, $this->editingSectionId, $name);
            session()->flash('instruction-status', 'Instruction section updated.');
        }

        $this->resetSectionEditor();
    }

    public function editSection(int $sectionId): void
    {
        $section = $this->ownedRecipe()->instructionSections()->find($sectionId);

        if (! $section instanceof RecipeInstructionSection) {
            abort(404);
        }

        $this->editingSectionId = $section->getKey();
        $this->sectionName = $section->name;
    }

    public function cancelSectionEdit(): void
    {
        $this->resetSectionEditor();
    }

    public function deleteSection(int $sectionId, RecipeInstructionWriter $writer): void
    {
        $writer->deleteSection($this->ownedRecipe(), $sectionId);
        $this->resetSectionEditor();

        if ((int) $this->sectionId === $sectionId) {
            $this->sectionId = null;
        }

        session()->flash('instruction-status', 'Instruction section removed; its steps are now unsectioned.');
    }

    public function moveSectionUp(int $sectionId, RecipeInstructionWriter $writer): void
    {
        $this->moveSection($sectionId, -1, $writer);
    }

    public function moveSectionDown(int $sectionId, RecipeInstructionWriter $writer): void
    {
        $this->moveSection($sectionId, 1, $writer);
    }

    /** @param list<int> $sectionIds */
    public function reorderSections(array $sectionIds, RecipeInstructionWriter $writer): void
    {
        Validator::make(
            ['sectionIds' => $sectionIds],
            ['sectionIds' => ['array'], 'sectionIds.*' => ['required', 'integer']]
        )->validate();
        $writer->reorderSections($this->ownedRecipe(), $sectionIds);
    }

    public function render()
    {
        $recipe = $this->ownedRecipe();

        return view('livewire.recipes.instructions', [
            'sections' => $recipe->instructionSections()->get(),
            'steps' => $recipe->instructionSteps()->with('section')->get(),
        ]);
    }

    private function ownedRecipe(): Recipe
    {
        $recipe = Recipe::query()->findOrFail($this->recipeId);
        $this->authorize('update', $recipe);

        return $recipe;
    }

    private function moveStep(int $stepId, int $offset, RecipeInstructionWriter $writer): void
    {
        $recipe = $this->ownedRecipe();
        $stepIds = $recipe->instructionSteps()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $index = array_search($stepId, $stepIds, true);

        if ($index === false) {
            abort(404);
        }

        $target = $index + $offset;

        if (! array_key_exists($target, $stepIds)) {
            return;
        }

        [$stepIds[$index], $stepIds[$target]] = [$stepIds[$target], $stepIds[$index]];
        $writer->reorderSteps($recipe, $stepIds);
    }

    private function moveSection(int $sectionId, int $offset, RecipeInstructionWriter $writer): void
    {
        $recipe = $this->ownedRecipe();
        $sectionIds = $recipe->instructionSections()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $index = array_search($sectionId, $sectionIds, true);

        if ($index === false) {
            abort(404);
        }

        $target = $index + $offset;

        if (! array_key_exists($target, $sectionIds)) {
            return;
        }

        [$sectionIds[$index], $sectionIds[$target]] = [$sectionIds[$target], $sectionIds[$index]];
        $writer->reorderSections($recipe, $sectionIds);
    }

    private function resetStepEditor(): void
    {
        $this->reset(['editingStepId', 'instructionText', 'sectionId']);
        $this->resetValidation(['instructionText', 'sectionId']);
    }

    private function resetSectionEditor(): void
    {
        $this->reset(['editingSectionId', 'sectionName']);
        $this->resetValidation(['sectionName']);
    }
}
