<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->longText('source_text')->nullable()->change();
            $table->text('submitted_url')->nullable()->after('source_text');
            $table->text('final_url')->nullable()->after('submitted_url');
            $table->string('extraction_method', 64)->nullable()->after('final_url');
            $table->string('extractor_identifier', 100)->nullable()->after('extraction_method');
            $table->string('extractor_version', 100)->nullable()->after('extractor_identifier');
            $table->timestamp('extracted_at')->nullable()->after('extractor_version');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_url',
                'final_url',
                'extraction_method',
                'extractor_identifier',
                'extractor_version',
                'extracted_at',
            ]);
            $table->longText('source_text')->nullable(false)->change();
        });
    }
};
