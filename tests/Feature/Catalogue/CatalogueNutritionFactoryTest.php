<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Nutrition\NutrientNormalizationWarning;
use App\Domain\Nutrition\NutrientProvenance;
use App\Models\CatalogueItemVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueNutritionFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_version_factory_does_not_create_a_nutrition_graph(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        $this->assertCount(0, $version->nutrientValues);
        $this->assertCount(0, $version->nutrientObservations);
    }

    public function test_complete_and_incomplete_states_are_deterministic(): void
    {
        $complete = CatalogueItemVersion::factory()->completeNutrition()->create();
        $incomplete = CatalogueItemVersion::factory()->incompleteNutrition()->create();

        $this->assertCount(10, $complete->nutrientValues);
        $this->assertCount(10, $complete->nutrientObservations);
        $this->assertCount(1, $incomplete->nutrientValues);
        $this->assertSame('protein', $incomplete->nutrientValues->sole()->nutrient->value);
    }

    public function test_provenance_basis_and_energy_states_create_the_named_shapes(): void
    {
        $manual = CatalogueItemVersion::factory()->manuallySubmittedNutrition()->create();
        $corrected = CatalogueItemVersion::factory()->correctedNutrition()->create();
        $serving = CatalogueItemVersion::factory()->perServingNutrition()->create();
        $conflict = CatalogueItemVersion::factory()->conflictingEnergyNutrition()->create();

        $this->assertSame(NutrientProvenance::ManuallySubmitted, $manual->nutrientValues->sole()->provenance);
        $this->assertSame(NutrientProvenance::Corrected, $corrected->nutrientValues->sole()->provenance);
        $this->assertSame('per_serving', $serving->nutrientValues->sole()->basis->value);
        $this->assertNotNull($serving->serving_amount);
        $this->assertSame(
            NutrientNormalizationWarning::EnergySourceConflict,
            $conflict->nutrientValues->firstWhere('nutrient.value', 'energy_kj')->normalization_warning,
        );
    }
}
