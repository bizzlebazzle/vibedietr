<?php

namespace App\Operations;

use RuntimeException;

final class RestrictedRecoveryFile
{
    public function read(string $path): array
    {
        $real = realpath($path);
        if ($real === false || is_link($path) || ! is_file($real) || filesize($real) > 32768
            || str_starts_with($real, realpath(base_path()).DIRECTORY_SEPARATOR)
            || (fileperms($real) & 0007) !== 0) {
            throw new RuntimeException('restricted_recovery_file_required');
        }

        return json_decode(file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
    }

    public function write(string $path, array $value): void
    {
        $parent = realpath(dirname($path));
        if ($parent === false || str_starts_with($parent.DIRECTORY_SEPARATOR, realpath(base_path()).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('restricted_recovery_file_required');
        }
        $oldMask = umask(0077);
        try {
            $file = fopen($path, 'x');
            if ($file === false) {
                throw new RuntimeException('restricted_recovery_file_exists');
            }
            try {
                $bytes = json_encode($value, JSON_THROW_ON_ERROR);
                if (fwrite($file, $bytes) !== strlen($bytes) || ! fflush($file) || ! fsync($file)) {
                    throw new RuntimeException('restricted_recovery_file_write_failed');
                }
            } finally {
                fclose($file);
            }
        } finally {
            umask($oldMask);
        }
    }
}
