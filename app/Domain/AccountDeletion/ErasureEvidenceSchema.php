<?php

namespace App\Domain\AccountDeletion;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

final class ErasureEvidenceSchema
{
    public function install(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create('erasure_evidence_holds', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->char('id', 26)->primary();
            $table->char('account_generation', 26)->index();
            $table->string('category', 40);
            $table->string('authority_reference', 128);
            $table->string('owner_approval_reference', 128);
            $table->string('operator_reference', 64);
            $table->string('state', 16);
            $table->longText('protected_evidence');
            $table->timestamp('created_at');
            $table->timestamp('review_at')->index();
            $table->timestamp('expires_at')->nullable()->index();
        });
    }
}
