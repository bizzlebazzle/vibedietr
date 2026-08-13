<?php

namespace Tests\Feature\Ingredients;

use App\Livewire\Ingredients\Index;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class IngredientIndexCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_full_partial_case_insensitive_name_and_barcode_for_owner_only(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->ingredientFor($owner, 'Golden Rolled Oats', 'OWNER-ABC-123');
        $this->ingredientFor($owner, 'Oat Milk', 'OWNER-XYZ-456');
        $this->ingredientFor($owner, 'Brown Rice', 'OWNER-RICE-789');
        $this->ingredientFor($otherUser, 'Golden Rolled Oats Private Other User', 'OTHER-ABC-123');

        Livewire::actingAs($owner)->test(Index::class)
            ->set('search', 'Golden Rolled Oats')
            ->assertSee('Golden Rolled Oats')
            ->assertDontSee('Oat Milk')
            ->set('search', 'oAt')
            ->assertSee('Golden Rolled Oats')
            ->assertSee('Oat Milk')
            ->assertDontSee('Brown Rice')
            ->assertDontSee('Golden Rolled Oats Private Other User')
            ->set('search', 'XYZ-45')
            ->assertSee('Oat Milk')
            ->assertDontSee('Golden Rolled Oats')
            ->assertDontSee('Golden Rolled Oats Private Other User');
    }

    public function test_non_matching_and_empty_search_show_current_behavior(): void
    {
        $owner = User::factory()->create();
        $this->ingredientFor($owner, 'Owner apples', 'APPLE-001');
        $this->ingredientFor($owner, 'Owner pears', 'PEAR-002');

        Livewire::actingAs($owner)->test(Index::class)
            ->set('search', 'no-current-match')
            ->assertSee('No ingredients found.')
            ->assertDontSee('Owner apples')
            ->set('search', '')
            ->assertSee('Owner apples')
            ->assertSee('Owner pears')
            ->assertDontSee('No ingredients found.');
    }

    public function test_first_and_second_pages_have_twelve_results_without_duplicates_or_other_users_records(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        foreach (range(1, 25) as $number) {
            $this->ingredientFor(
                $owner,
                sprintf('Owner page item %02d', $number),
                sprintf('OWNER-%02d', $number),
                Carbon::parse('2026-01-01 00:00:00')->addMinutes($number),
            );
        }

        $this->ingredientFor($otherUser, 'Other user pagination secret', 'OTHER-PAGE');

        $firstPage = Livewire::actingAs($owner)->test(Index::class);
        foreach (range(14, 25) as $number) {
            $firstPage->assertSee(sprintf('Owner page item %02d', $number));
        }
        $firstPage
            ->assertDontSee('Owner page item 13')
            ->assertDontSee('Other user pagination secret');

        $secondPage = Livewire::actingAs($owner)->test(Index::class)->call('setPage', 2);
        foreach (range(2, 13) as $number) {
            $secondPage->assertSee(sprintf('Owner page item %02d', $number));
        }
        $secondPage
            ->assertDontSee('Owner page item 14')
            ->assertDontSee('Owner page item 25')
            ->assertDontSee('Other user pagination secret');

        Livewire::actingAs($owner)->test(Index::class)
            ->call('setPage', 3)
            ->assertSee('Owner page item 01')
            ->assertDontSee('Owner page item 02')
            ->assertDontSee('Other user pagination secret');
    }

    public function test_search_paginates_owner_matches_and_resets_to_first_page_when_changed(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        foreach (range(1, 14) as $number) {
            $this->ingredientFor(
                $owner,
                sprintf('Matching lentil %02d', $number),
                sprintf('LENTIL-%02d', $number),
                Carbon::parse('2026-02-01 00:00:00')->addMinutes($number),
            );
        }

        $this->ingredientFor($owner, 'Newest unrelated quinoa', 'QUINOA-01', Carbon::parse('2026-03-01'));
        $this->ingredientFor($otherUser, 'Matching lentil other user', 'OTHER-LENTIL');

        $component = Livewire::actingAs($owner)->test(Index::class)
            ->set('search', 'Matching lentil');

        foreach (range(3, 14) as $number) {
            $component->assertSee(sprintf('Matching lentil %02d', $number));
        }
        $component
            ->assertDontSee('Matching lentil 02')
            ->assertDontSee('Matching lentil other user')
            ->call('setPage', 2)
            ->assertSee('Matching lentil 01')
            ->assertSee('Matching lentil 02')
            ->assertDontSee('Matching lentil 03')
            ->set('search', '')
            ->assertSee('Newest unrelated quinoa')
            ->assertSee('Matching lentil 14')
            ->assertDontSee('Matching lentil 01')
            ->assertDontSee('Matching lentil other user');
    }

    private function ingredientFor(
        User $owner,
        string $name,
        string $barcode,
        ?Carbon $createdAt = null,
    ): Ingredient {
        $ingredient = Ingredient::query()->create([
            'user_id' => $owner->id,
            'name' => $name,
            'barcode' => $barcode,
            'quantity' => 1,
            'quantity_unit' => 'g',
        ]);

        if ($createdAt !== null) {
            $ingredient->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $ingredient;
    }
}
