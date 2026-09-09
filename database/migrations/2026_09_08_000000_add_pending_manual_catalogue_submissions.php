<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->index();
            $table->enum('manual_food_classification', ['generic', 'branded'])->nullable();
            $table->string('brand')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('food_form')->nullable();
            $table->string('preparation')->nullable();
            $table->string('treatment')->nullable();
            $table->string('composition', 1000)->nullable();
        });
        DB::table('catalogue_item_versions')
            ->whereNotNull('name')
            ->update(['normalized_name' => DB::raw('LOWER(TRIM(name))')]);

        Schema::table('catalogue_items', function (Blueprint $table) {
            $table->foreignId('suggested_replacement_catalogue_item_id')
                ->nullable()
                ->after('current_catalogue_item_version_id')
                ->constrained('catalogue_items')
                ->nullOnDelete();
        });

        Schema::create('catalogue_item_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogue_item_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->index();
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->unique(['catalogue_item_id', 'normalized_alias']);
        });

        Schema::create('catalogue_duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('first_catalogue_item_id')->constrained('catalogue_items')->cascadeOnDelete();
            $table->foreignId('second_catalogue_item_id')->constrained('catalogue_items')->cascadeOnDelete();
            $table->enum('status', ['pending_review', 'confirmed_distinct', 'confirmed_duplicate', 'dismissed']);
            $table->enum('evidence', ['exact_primary_name', 'approved_alias']);
            $table->string('distinction_explanation', 500)->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(
                ['first_catalogue_item_id', 'second_catalogue_item_id'],
                'catalogue_duplicate_pair_unique',
            );
        });
        DB::statement('ALTER TABLE catalogue_duplicate_candidates ADD CONSTRAINT catalogue_duplicate_pair_order CHECK (first_catalogue_item_id < second_catalogue_item_id)');

        DB::statement("ALTER TABLE recipe_ingredient_line_matches MODIFY provenance ENUM(
            'manually_selected_by_creator', 'owner_confirmed_replacement') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE recipe_ingredient_line_matches MODIFY provenance ENUM(
            'manually_selected_by_creator') NOT NULL");
        Schema::dropIfExists('catalogue_duplicate_candidates');
        Schema::dropIfExists('catalogue_item_aliases');
        Schema::table('catalogue_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suggested_replacement_catalogue_item_id');
        });
        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->dropColumn([
                'normalized_name',
                'manual_food_classification',
                'brand',
                'manufacturer',
                'food_form',
                'preparation',
                'treatment',
                'composition',
            ]);
        });
    }
};
