<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('source_format', 32);
            $table->longText('source_text');
            $table->string('status', 32);
            $table->string('parser_identifier', 100)->nullable();
            $table->string('parser_version', 100)->nullable();
            $table->string('correlation_id', 64)->index();
            $table->string('idempotency_key', 160)->unique();
            $table->foreignId('recipe_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->boolean('requires_review')->default(true);
            $table->json('warnings')->nullable();
            $table->json('provenance')->nullable();
            $table->string('completion_classification', 64)->nullable();
            $table->string('failure_category', 64)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->unsignedTinyInteger('manual_retry_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('recipe_ingredient_lines', function (Blueprint $table) {
            $table->boolean('requires_review')->default(false)->after('notes');
            $table->json('parser_warnings')->nullable()->after('requires_review');
            $table->json('uncertain_fields')->nullable()->after('parser_warnings');
        });

        Schema::table('recipe_instruction_steps', function (Blueprint $table) {
            $table->boolean('requires_review')->default(false)->after('text');
            $table->json('parser_warnings')->nullable()->after('requires_review');
            $table->json('uncertain_fields')->nullable()->after('parser_warnings');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_instruction_steps', function (Blueprint $table) {
            $table->dropColumn(['requires_review', 'parser_warnings', 'uncertain_fields']);
        });

        Schema::table('recipe_ingredient_lines', function (Blueprint $table) {
            $table->dropColumn(['requires_review', 'parser_warnings', 'uncertain_fields']);
        });

        Schema::dropIfExists('recipe_imports');
    }
};
