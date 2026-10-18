<?php

namespace App\Domain\AccountExports;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ExportStorage
{
    public function disk(string $name): Filesystem
    {
        $config = config('filesystems.disks.'.$name);
        if (! is_array($config) || $name === 'public' || ($config['serve'] ?? false) || ($config['visibility'] ?? 'private') === 'public') {
            throw new RuntimeException('export_storage_must_be_private');
        }
        if (($config['driver'] ?? '') === 's3') {
            // Bound storage calls within the existing 60-second job budget.
            return Storage::build([...$config, 'http' => ['connect_timeout' => 3, 'timeout' => 10]]);
        }

        return Storage::disk($name);
    }
}
