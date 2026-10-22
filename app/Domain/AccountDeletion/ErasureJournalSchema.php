<?php

namespace App\Domain\AccountDeletion;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/** Provision on dedicated stores, never in application-database migrations. */
final class ErasureJournalSchema
{
    public function install(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create('erasure_journal_entries', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('sequence')->primary();
            $table->char('operation_id', 26)->unique();
            $table->string('generation', 128);
            $table->char('previous_digest', 64);
            $table->char('digest', 64);
            $table->longText('ciphertext');
        });
    }
}
