<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NUTRIENTS = [
        'energy_kcal', 'energy_kj', 'fat', 'saturated_fat', 'carbohydrates',
        'sugars', 'fibre', 'protein', 'salt', 'sodium',
    ];

    private const BASES = ['per_100g', 'per_100ml', 'per_serving'];

    private const UNITS = ['kcal', 'kj', 'g', 'mg'];

    private const STATUSES = [
        'known', 'missing', 'trace', 'below_limit', 'approximate',
        'not_significant_source',
    ];

    private const PROVENANCE = ['imported', 'manually_submitted', 'derived', 'corrected'];

    public function up(): void
    {
        Schema::create('catalogue_nutrient_observations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('catalogue_item_version_id')
                ->constrained('catalogue_item_versions', indexName: 'cat_nutr_obs_version_fk')
                ->cascadeOnDelete();
            $table->enum('nutrient', self::NUTRIENTS);
            $table->enum('basis', self::BASES);
            $table->decimal('value', 38, 18)->unsigned()->nullable();
            $table->decimal('threshold_value', 38, 18)->unsigned()->nullable();
            $table->enum('unit', self::UNITS);
            $table->enum('status', self::STATUSES);
            $table->enum('provenance', ['imported', 'manually_submitted', 'corrected']);
            $table->enum('source', ['manual', 'openfoodfacts'])->nullable();
            $table->string('source_field', 64)->nullable();
            $table->unsignedSmallInteger('source_scale')->nullable();
            $table->boolean('precision_reduced')->default(false);
            $table->timestamp('source_observed_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->unsignedSmallInteger('normalization_policy_version');
            $table->timestamps();
            $table->index(
                ['catalogue_item_version_id', 'nutrient', 'basis'],
                'catalogue_nutrient_observation_lookup',
            );
        });

        Schema::create('catalogue_nutrient_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('catalogue_item_version_id')
                ->constrained('catalogue_item_versions', indexName: 'cat_nutr_value_version_fk')
                ->cascadeOnDelete();
            $table->foreignUlid('source_observation_id')
                ->constrained('catalogue_nutrient_observations', indexName: 'cat_nutr_value_source_fk')
                ->cascadeOnDelete();
            $table->enum('nutrient', self::NUTRIENTS);
            $table->enum('basis', self::BASES);
            $table->decimal('value', 38, 18)->unsigned()->nullable();
            $table->decimal('threshold_value', 38, 18)->unsigned()->nullable();
            $table->enum('unit', self::UNITS);
            $table->enum('status', self::STATUSES);
            $table->enum('provenance', self::PROVENANCE);
            $table->enum('derivation', [
                'energy_kcal_from_kj', 'energy_kj_from_kcal',
            ])->nullable();
            $table->enum('normalization_warning', [
                'energy_source_conflict', 'source_precision_reduced',
            ])->nullable();
            $table->unsignedSmallInteger('normalization_policy_version');
            $table->timestamps();
            $table->unique(
                ['catalogue_item_version_id', 'nutrient', 'basis'],
                'catalogue_nutrient_value_unique_fact',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_nutrient_values');
        Schema::dropIfExists('catalogue_nutrient_observations');
    }
};
