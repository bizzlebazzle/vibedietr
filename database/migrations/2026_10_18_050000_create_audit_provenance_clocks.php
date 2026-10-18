<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_provenance_clocks', function (Blueprint $table): void {
            $table->foreignUlid('event_id')->primary()->constrained('audit_events')->cascadeOnDelete();
            $table->timestamp('ended_at');
            $table->char('integrity_hash', 64);
        });
    }

    public function down(): void
    {
        if (DB::table('audit_provenance_clocks')->exists()) {
            throw new RuntimeException('Retention clocks require a forward migration.');
        }
        Schema::dropIfExists('audit_provenance_clocks');
    }
};
