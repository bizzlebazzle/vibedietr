<?php

use App\Audit\Enums\AuditAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('active_key', 64)->nullable()->unique();
            $table->string('state', 16)->default('queued');
            $table->string('correlation_id', 64);
            $table->string('disk', 32);
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        $this->actions(true);
    }

    public function down(): void
    {
        if (DB::table('account_exports')->exists() || DB::table('audit_events')->where('action', 'account.export_event')->exists()) {
            throw new RuntimeException('Account export history requires a forward migration; rollback refused.');
        }
        Schema::dropIfExists('account_exports');
        $this->actions(false);
    }

    private function actions(bool $include): void
    {
        $values = array_filter(array_column(AuditAction::cases(), 'value'), fn ($action) => $include || $action !== 'account.export_event');
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM('".implode("','", $values)."') NOT NULL");
    }
};
