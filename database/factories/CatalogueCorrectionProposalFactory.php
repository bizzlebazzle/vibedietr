<?php

namespace Database\Factories;

use App\Domain\Catalogue\CatalogueCorrectionProposalState;
use App\Models\CatalogueCorrectionChange;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItemVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogueCorrectionProposal> */
class CatalogueCorrectionProposalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $version = CatalogueItemVersion::factory()->current()->create();

        return [
            'catalogue_item_id' => $version->catalogue_item_id,
            'base_catalogue_item_version_id' => $version->id,
            'proposer_user_id' => User::factory(),
            'reason' => 'The displayed catalogue fact is inaccurate.',
            'state' => CatalogueCorrectionProposalState::Pending,
            'submitted_at' => now()->utc(),
            'decided_at' => null,
        ];
    }

    public function forVersion(CatalogueItemVersion $version): static
    {
        return $this->state(fn (): array => [
            'catalogue_item_id' => $version->catalogue_item_id,
            'base_catalogue_item_version_id' => $version->id,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['state' => CatalogueCorrectionProposalState::Accepted, 'decided_at' => now()->utc()]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['state' => CatalogueCorrectionProposalState::Rejected, 'decided_at' => now()->utc()]);
    }

    public function withNameChange(string $before = 'Old name', string $after = 'Corrected name'): static
    {
        return $this->afterCreating(fn (CatalogueCorrectionProposal $proposal) => CatalogueCorrectionChange::query()->forceCreate([
            'proposal_id' => $proposal->id,
            'field_key' => 'name',
            'before_value' => ['value' => $before],
            'proposed_value' => ['value' => $after],
        ]));
    }
}
