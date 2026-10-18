<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM audit_events LIKE 'action'");
        if (! str_contains($column->Type, "'plan.retained_moderated'")) {
            $enum = substr($column->Type, 0, -1).",'plan.retained_moderated')";
            DB::statement("ALTER TABLE audit_events MODIFY action {$enum} NOT NULL");
        }
    }

    public function down(): void
    {
        // Additive audit enum values cannot be removed while historical events exist.
    }
};
