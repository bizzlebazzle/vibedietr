<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->unsignedInteger('package_count')->nullable();
            $table->string('item_type', 32)->nullable();
            $table->decimal('amount_per_item', 38, 18)->unsigned()->nullable();
            $table->string('amount_per_item_unit', 32)->nullable();
            $table->decimal('servings_per_item', 38, 18)->unsigned()->nullable();
            $table->decimal('serving_amount', 38, 18)->unsigned()->nullable();
            $table->string('serving_amount_unit', 32)->nullable();
            $table->enum('serving_amount_basis', [
                'source',
                'derived_amount_per_item_divided_by_servings_per_item',
            ])->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->dropColumn([
                'package_count',
                'item_type',
                'amount_per_item',
                'amount_per_item_unit',
                'servings_per_item',
                'serving_amount',
                'serving_amount_unit',
                'serving_amount_basis',
            ]);
        });
    }
};
