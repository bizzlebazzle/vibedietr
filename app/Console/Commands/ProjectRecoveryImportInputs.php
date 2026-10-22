<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ProjectRecoveryImportInputs extends Command
{
    protected $signature = 'operations:recovery-input-projection {--write= : New restricted SQL file} {--quiesced : All writers and DDL stopped}';

    protected $description = 'Capture import metadata and durable draft references while excluding transient SQL source text';

    public function handle(): int
    {
        if (! $this->option('quiesced') || ! $this->option('write')) {
            return self::FAILURE;
        }
        try {
            $db = DB::connection();
            if ($db->getDriverName() !== 'mysql') {
                throw new RuntimeException('recovery_mysql_required');
            }
            $columns = $db->getSchemaBuilder()->getColumnListing('recipe_imports');
            $quote = fn (string $name): string => '`'.str_replace('`', '``', $name).'`';
            $path = (string) $this->option('write');
            $parent = realpath(dirname($path));
            if ($parent === false || str_starts_with($parent.DIRECTORY_SEPARATOR, realpath(base_path()).DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('restricted_recovery_file_required');
            }
            $mask = umask(0077);
            try {
                $file = fopen($path, 'x');
            } finally {
                umask($mask);
            }
            if ($file === false) {
                throw new RuntimeException('recovery_file_create_failed');
            }
            $write = function (string $bytes) use ($file): void {
                if (fwrite($file, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('recovery_file_write_failed');
                }
            };
            $write("SET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($db->table('recipe_imports')->orderBy('id')->cursor() as $row) {
                $row->source_text = null;
                $values = [];
                foreach ($columns as $column) {
                    $value = $row->{$column};
                    $quoted = $value === null ? 'NULL' : $db->getPdo()->quote((string) $value);
                    if ($quoted === false) {
                        throw new RuntimeException('recovery_quote_failed');
                    }
                    $values[] = $quoted;
                }
                $write('INSERT INTO `recipe_imports` ('.implode(',', array_map($quote, $columns)).') VALUES ('.implode(',', $values).");\n");
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
            if (! fflush($file) || ! fsync($file)) {
                throw new RuntimeException('recovery_file_sync_failed');
            }
        } catch (Throwable) {
            $this->error('Recovery input projection failed. Keep the set restricted and ineligible.');

            return self::FAILURE;
        } finally {
            if (isset($file) && is_resource($file)) {
                fclose($file);
            }
        }
        $this->info('Transient SQL source text excluded; verify the complete restored database before release.');

        return self::SUCCESS;
    }
}
