<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_versions', fn (Blueprint $table) => $table->json('retained_public_nutrition')->nullable());
    }

    public function down(): void
    {
        if (DB::table('recipe_versions')->whereNotNull('retained_public_nutrition')->exists()) {
            throw new RuntimeException('Retained public presentation requires a forward migration.');
        }
        Schema::table('recipe_versions', fn (Blueprint $table) => $table->dropColumn('retained_public_nutrition'));
    }
};
