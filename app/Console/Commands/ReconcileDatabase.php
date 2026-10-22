<?php

namespace App\Console\Commands;

use App\Operations\DatabaseReconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReconcileDatabase extends Command
{
    protected $signature = 'operations:reconcile-database
        {--write= : Create a new restricted baseline file}
        {--compare= : Compare with a restricted baseline file}
        {--additive : Permit additive schema and migration-ledger changes only}
        {--quiesced : Assert all writers and DDL are stopped}';

    protected $description = 'Check MySQL integrity and reconcile frozen database counts, digests and migration history';

    public function handle(DatabaseReconciliation $reconciliation): int
    {
        if (! $this->option('quiesced') || (bool) $this->option('write') === (bool) $this->option('compare') || ($this->option('additive') && ! $this->option('compare'))) {
            $this->error('Assert quiescence and select exactly one of --write or --compare.');

            return self::FAILURE;
        }
        try {
            if ($this->option('write')) {
                $manifest = $reconciliation->capture(DB::connection());
                $oldMask = umask(0077);
                try {
                    $file = fopen((string) $this->option('write'), 'x');
                    if ($file === false) {
                        throw new \RuntimeException('manifest_create_failed');
                    }
                    try {
                        $bytes = json_encode($manifest, JSON_THROW_ON_ERROR);
                        if (fwrite($file, $bytes) !== strlen($bytes)) {
                            throw new \RuntimeException('manifest_write_failed');
                        }
                    } finally {
                        fclose($file);
                    }
                } finally {
                    umask($oldMask);
                }
            } else {
                $manifest = json_decode(file_get_contents((string) $this->option('compare')), true, flags: JSON_THROW_ON_ERROR);
                $reconciliation->verify(DB::connection(), $manifest, (bool) $this->option('additive'));
            }
        } catch (Throwable) {
            // SQL/driver exceptions may contain private values or connection credentials.
            $this->error('Database verification failed; keep the target closed. Inspect through the restricted operations channel.');

            return self::FAILURE;
        }
        $this->info('Database integrity and reconciliation passed. This does not authorize restore release.');

        return self::SUCCESS;
    }
}
