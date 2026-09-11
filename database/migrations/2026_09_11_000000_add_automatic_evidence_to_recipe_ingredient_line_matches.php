<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_ingredient_line_matches', function (Blueprint $table) {
            $table->decimal('candidate_score', 19, 18)->unsigned()->nullable()->after('catalogue_item_version_id');
            $table->enum('confidence_band', ['reviewable', 'high'])->nullable()->after('candidate_score');
            $table->unsignedInteger('threshold_version')->nullable()->after('confidence_band');
        });

        DB::statement("ALTER TABLE recipe_ingredient_line_matches MODIFY provenance ENUM('manually_selected_by_creator','owner_confirmed_replacement','automatically_selected') NOT NULL");
        DB::statement(<<<'SQL'
            ALTER TABLE recipe_ingredient_line_matches
            ADD CONSTRAINT recipe_match_evidence_consistency CHECK (
                (
                    provenance IN ('manually_selected_by_creator', 'owner_confirmed_replacement')
                    AND candidate_score IS NULL
                    AND confidence_band IS NULL
                    AND threshold_version IS NULL
                    AND review_state = 'confirmed'
                )
                OR
                (
                    provenance = 'automatically_selected'
                    AND candidate_score IS NOT NULL
                    AND confidence_band IS NOT NULL
                    AND threshold_version >= 1
                    AND candidate_score BETWEEN 0.9500 AND 1.0000
                    AND (
                        (
                            confidence_band = 'reviewable'
                            AND candidate_score < 0.9900
                            AND review_state IN ('needs_review', 'confirmed')
                        )
                        OR
                        (
                            confidence_band = 'high'
                            AND candidate_score >= 0.9900
                            AND review_state = 'confirmed'
                        )
                    )
                )
            )
            SQL);
    }

    public function down(): void
    {
        if (DB::table('recipe_ingredient_line_matches')->where('provenance', 'automatically_selected')->exists()) {
            throw new RuntimeException('Automatic recipe match evidence requires a forward migration; rollback refused.');
        }

        DB::statement('ALTER TABLE recipe_ingredient_line_matches DROP CHECK recipe_match_evidence_consistency');
        DB::statement("ALTER TABLE recipe_ingredient_line_matches MODIFY provenance ENUM('manually_selected_by_creator','owner_confirmed_replacement') NOT NULL");

        Schema::table('recipe_ingredient_line_matches', function (Blueprint $table) {
            $table->dropColumn(['candidate_score', 'confidence_band', 'threshold_version']);
        });
    }
};
