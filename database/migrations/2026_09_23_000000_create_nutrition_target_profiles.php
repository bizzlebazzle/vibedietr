<?php

use App\Domain\NutritionTargets\NutritionTargetType;
use App\Models\NutritionTargetProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_target_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'is_default']);
            $table->index(['user_id', 'name']);
        });

        Schema::create('nutrition_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nutrition_target_profile_id')->constrained()->cascadeOnDelete();
            $table->string('nutrient', 64);
            $table->enum('type', array_column(NutritionTargetType::cases(), 'value'));
            $table->decimal('exact_value', 38, 18)->nullable();
            $table->decimal('minimum_value', 38, 18)->nullable();
            $table->decimal('maximum_value', 38, 18)->nullable();
            $table->timestamps();

            $table->unique(['nutrition_target_profile_id', 'nutrient']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE nutrition_target_profiles
            ADD CONSTRAINT nutrition_target_profiles_default_check CHECK (is_default IS NULL OR is_default = 1)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE nutrition_targets
            ADD CONSTRAINT nutrition_targets_shape_check CHECK (
                (type = 'exact' AND exact_value IS NOT NULL AND minimum_value IS NULL AND maximum_value IS NULL)
                OR (type = 'minimum' AND exact_value IS NULL AND minimum_value IS NOT NULL AND maximum_value IS NULL)
                OR (type = 'maximum' AND exact_value IS NULL AND minimum_value IS NULL AND maximum_value IS NOT NULL)
                OR (type = 'range' AND exact_value IS NULL AND minimum_value IS NOT NULL AND maximum_value IS NOT NULL AND minimum_value <= maximum_value)
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE nutrition_targets
            ADD CONSTRAINT nutrition_targets_non_negative_check CHECK (
                (exact_value IS NULL OR exact_value >= 0)
                AND (minimum_value IS NULL OR minimum_value >= 0)
                AND (maximum_value IS NULL OR maximum_value >= 0)
            )
        SQL);

        $now = now();
        DB::table('users')->select('id')->orderBy('id')->chunkById(500, function ($users) use ($now): void {
            DB::table('nutrition_target_profiles')->insert($users->map(fn ($user): array => [
                'user_id' => $user->id,
                'name' => NutritionTargetProfile::DEFAULT_NAME,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_targets');
        Schema::dropIfExists('nutrition_target_profiles');
    }
};
