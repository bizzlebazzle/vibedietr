<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_consumption_archives', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('source_plan_generation', 26)->unique();
            $table->longText('history');
            $table->timestamps();
        });
        Schema::create('erasure_operator_mappings', function (Blueprint $table): void {
            $table->char('identity_id', 26);
            $table->char('event_id', 26)->primary();
            $table->text('protected_reference');
            $table->timestamp('expires_at')->index();
        });
        Schema::table('retained_plan_removals', fn (Blueprint $table) => $table->char('resource_generation', 26)->nullable()->unique());
        foreach (['recipes', 'meal_plans'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->char('erasure_generation', 26)->nullable()->unique());
            DB::table($name)->orderBy('id')->chunkById(200, function ($rows) use ($name): void {
                foreach ($rows as $row) {
                    DB::table($name)->where('id', $row->id)->update(['erasure_generation' => (string) Str::ulid()]);
                }
            });
        }
    }

    public function down(): void
    {
        if (DB::table('account_deletions')->exists() || DB::table('account_erasure_receipts')->exists()) {
            throw new RuntimeException('Referenced erasure generations require a forward migration.');
        }
        if (DB::table('erasure_operator_mappings')->exists()) {
            throw new RuntimeException('Protected operator mappings require a forward migration.');
        }
        if (DB::table('private_consumption_archives')->exists()) {
            throw new RuntimeException('Private history requires a forward migration.');
        }
        Schema::dropIfExists('private_consumption_archives');
        Schema::dropIfExists('erasure_operator_mappings');
        Schema::table('retained_plan_removals', fn (Blueprint $table) => $table->dropColumn('resource_generation'));
        foreach (['recipes', 'meal_plans'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('erasure_generation'));
        }
    }
};
