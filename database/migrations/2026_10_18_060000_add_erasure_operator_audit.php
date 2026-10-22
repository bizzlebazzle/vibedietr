<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM audit_events LIKE 'action'");
        if (! str_contains($column->Type, "'account.erasure_operator_event'")) {
            $enum = substr($column->Type, 0, -1).",'account.erasure_operator_event')";
            DB::statement("ALTER TABLE audit_events MODIFY action {$enum} NOT NULL");
        }
    }

    public function down(): void
    {
        // Existing accountability history requires additive forward migrations.
    }
};
