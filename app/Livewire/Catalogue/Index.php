<?php

namespace App\Livewire\Catalogue;

use App\Domain\Catalogue\CatalogueReadQuery;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->resetPage('legacyPage');
    }

    public function render(CatalogueReadQuery $catalogue)
    {
        abort_unless(config('catalogue.read_cutover'), 404);

        $user = auth()->user();
        $user = $user instanceof User ? $user : null;

        return view('livewire.catalogue.index', [
            'catalogueItems' => $catalogue->paginate($user, $this->search),
            'legacyIngredients' => $user === null
                ? null
                : $catalogue->paginateLegacyFallback($user, $this->search),
        ]);
    }
}
