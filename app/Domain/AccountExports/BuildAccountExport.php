<?php

namespace App\Domain\AccountExports;

use App\Models\AccountExport;
use App\Queue\Exceptions\NonRetryableJobException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

class BuildAccountExport
{
    public function generate(string $id): void
    {
        if (DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level !== 'REPEATABLE-READ') {
            throw new NonRetryableJobException('export_requires_repeatable_read');
        }
        // The export row serializes generation, cleanup and download opening. InnoDB repeatable reads
        // retain a consistent committed content view without locking the user's domain graphs.
        DB::transaction(function () use ($id): void {
            $export = AccountExport::query()->lockForUpdate()->find($id);
            if (! $export || in_array($export->state, ['ready', 'expired', 'deleted'], true) || now()->utc()->greaterThanOrEqualTo($export->expires_at) || $export->user_id === null) {
                return;
            }
            $path = $export->stagingPath();
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('export_staging_unavailable');
            }
            if (file_put_contents($path, '') === false) {
                throw new RuntimeException('export_staging_unavailable');
            }
            if (! chmod($directory, 0700) || ! chmod($path, 0600)) {
                throw new RuntimeException('export_staging_permissions');
            }
            $zip = new ZipArchive;
            $opened = false;
            try {
                if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('export_archive_open');
                }
                $opened = true;
                $inventory = [];
                $started = hrtime(true);
                $bytes = 0;
                foreach (app(AccountExportProjection::class)->domains((int) $export->user_id) as $domain => $produce) {
                    $data = $produce();
                    $envelope = app(ExportContract::class)->project($domain.'_envelope', ['schema_version' => '1.0.0', 'data' => $data]);
                    $json = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                    $bytes += strlen($json);
                    if ($bytes > 64 * 1024 * 1024 || memory_get_usage(true) > 192 * 1024 * 1024 || (hrtime(true) - $started) / 1e9 > 40) {
                        throw new NonRetryableJobException('export_resource_limit');
                    }
                    $file = 'data/'.$domain.'.json';
                    if (! $zip->addFromString($file, $json)) {
                        throw new RuntimeException('export_archive_write');
                    }
                    $unit = match ($domain) {
                        'diary' => 'transitions', 'targets' => 'profiles', 'organisation' => 'collections', 'proposals' => 'corrections', 'account' => 'account', default => $domain,
                    };
                    $count = is_array($data) ? count($data) : ($domain === 'account' ? 1 : count($data->$unit));
                    $inventory[] = ['path' => $file, 'media_type' => 'application/json', 'counting_unit' => $unit, 'count' => $count, 'bytes' => strlen($json)];
                    unset($data, $envelope, $json);
                }
                $generated = now()->utc();
                $manifest = [
                    'format' => 'vibedietr-account-export', 'schema_version' => '1.0.0',
                    'schema_path' => 'schema/account-export-1.0.0.schema.json', 'export_id' => $export->getKey(),
                    'generated_at' => $generated->format('Y-m-d\TH:i:s\Z'), 'encoding' => 'UTF-8', 'files' => $inventory,
                    'excluded_categories' => ['credentials', 'foreign_account_data', 'transient_input_files', 'restricted_operational_evidence', 'source_url_and_internal_import_references', 'raw_provider_payloads'],
                    'understood_omissions' => [],
                ];
                $extraFiles = [];
                foreach (['account-export-1.0.0.schema.json', 'data-dictionary.md'] as $file) {
                    $extraFiles['schema/'.$file] = file_get_contents(resource_path('account-export/1.0.0/'.$file));
                }
                $readme = file_get_contents(resource_path('account-export/1.0.0/README.txt'));
                $extraFiles['README.txt'] = $readme;
                $links = '';
                foreach ($inventory as $file) {
                    $links .= '<li><a href="'.htmlspecialchars($file['path'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($file['counting_unit'], ENT_QUOTES, 'UTF-8').'</a>: '.$file['count'].'</li>';
                }
                $extraFiles['index.html'] = '<!doctype html><html lang="en"><meta charset="UTF-8"><meta name="viewport" content="width=device-width"><title>Your VibeDietr export</title><main><h1>Your VibeDietr export — schema 1.0.0</h1><p>Start with <a href="manifest.json">the manifest</a> and <a href="schema/data-dictionary.md">data dictionary</a>.</p><ul>'.$links.'</ul><pre>'.htmlspecialchars($readme, ENT_QUOTES, 'UTF-8').'</pre></main></html>';
                foreach ($extraFiles as $name => $content) {
                    $this->add($zip, $name, $content);
                    $inventory[] = ['path' => $name, 'media_type' => match (pathinfo($name, PATHINFO_EXTENSION)) {
                        'json' => 'application/json', 'html' => 'text/html', default => 'text/plain'
                    }, 'counting_unit' => 'documents', 'count' => 1, 'bytes' => strlen($content)];
                }
                $manifest['files'] = $inventory;
                $manifest = app(ExportContract::class)->project('manifest', $manifest);
                $this->add($zip, 'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
                if (! $zip->close()) {
                    throw new RuntimeException('export_archive_finalize');
                }
                $opened = false;
                $stream = fopen($path, 'rb');
                try {
                    if (! app(ExportStorage::class)->disk($export->disk)->put($export->storageKey(), $stream, ['visibility' => 'private'])) {
                        throw new RuntimeException('export_storage_failed');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if (Date::now('UTC')->greaterThanOrEqualTo($export->expires_at)) {
                    throw new RuntimeException('export_expired_during_generation');
                }
                $export->forceFill(['state' => 'ready', 'generated_at' => $generated])->save();
                app(ExportAudit::class)->record($export, 'generated');
            } finally {
                if ($opened) {
                    $zip->close();
                }
                unlink($path);
            }
        });
    }

    private function add(ZipArchive $zip, string $name, string $bytes): void
    {
        if (! $zip->addFromString($name, $bytes)) {
            throw new RuntimeException('export_archive_write');
        }
    }
}
