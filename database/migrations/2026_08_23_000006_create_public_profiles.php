<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('attribution_name', 80)->nullable();
            $table->boolean('profile_enabled')->default(false);
            $table->boolean('show_public_recipes')->default(false);
            $table->boolean('show_public_remixes')->default(false);
            $table->timestamps();
        });

        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->string('public_attribution_name', 80)->nullable()->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->dropColumn('public_attribution_name');
        });

        Schema::dropIfExists('public_profiles');
    }
};
