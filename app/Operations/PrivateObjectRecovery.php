<?php

namespace App\Operations;

use Aws\S3\S3Client;
use RuntimeException;

final class PrivateObjectRecovery
{
    /** @param array<string, mixed> $ownership Untrusted decoded operator inventory, validated below.
     * @param  list<string>  $excludedPrefixes
     * @return array<string, mixed>
     */
    public function capture(S3Client $client, string $bucket, string $directory, array $ownership, array $excludedPrefixes): array
    {
        $this->assertUnversioned($client, $bucket);
        if (file_exists($directory) || ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('recovery_object_directory_exists');
        }
        $objects = [];
        foreach ($this->keys($client, $bucket) as $key) {
            if ($this->excluded($key, $excludedPrefixes)) {
                continue;
            }
            $owner = $ownership[$key] ?? null;
            if (! is_array($owner) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $owner['owner_generation'] ?? '') || ! is_string($owner['resource'] ?? null) || strlen($owner['resource']) > 128 || $owner['resource'] === '') {
                throw new RuntimeException('recovery_object_ownership_missing');
            }
            $file = hash('sha256', $key);
            $result = $client->getObject(['Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $directory.'/'.$file]);
            chmod($directory.'/'.$file, 0600);
            $size = filesize($directory.'/'.$file);
            if ($size !== (int) $result['ContentLength']) {
                throw new RuntimeException('recovery_object_size_mismatch');
            }
            $objects[$key] = ['owner_generation' => $owner['owner_generation'], 'resource' => $owner['resource'], 'file' => $file, 'bytes' => $size, 'sha256' => hash_file('sha256', $directory.'/'.$file)];
        }
        if (array_diff(array_keys($ownership), array_keys($objects)) !== []) {
            throw new RuntimeException('recovery_required_object_missing');
        }
        ksort($objects, SORT_STRING);
        $manifest = ['format' => 1, 'excluded_prefixes' => $excludedPrefixes, 'objects' => $objects];
        $bytes = json_encode($manifest, JSON_THROW_ON_ERROR);
        if (file_put_contents($directory.'/inventory.json', $bytes) !== strlen($bytes)) {
            throw new RuntimeException('recovery_manifest_write_failed');
        }
        chmod($directory.'/inventory.json', 0600);

        return $manifest;
    }

    /** Only copies into an empty isolated bucket. DEP-08 must filter erasures before serving. */
    public function restore(S3Client $client, string $bucket, string $directory): void
    {
        $this->assertUnversioned($client, $bucket);
        if ($this->keys($client, $bucket) !== []) {
            throw new RuntimeException('recovery_target_not_empty');
        }
        $manifest = $this->localManifest($directory);
        foreach ($manifest['objects'] as $key => $object) {
            $stream = fopen($directory.'/'.$object['file'], 'rb');
            if ($stream === false) {
                throw new RuntimeException('recovery_object_read_failed');
            }
            try {
                $client->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => $stream]);
            } finally {
                fclose($stream);
            }
        }
        $this->verify($client, $bucket, $directory);
    }

    public function verify(S3Client $client, string $bucket, string $directory): void
    {
        $this->assertUnversioned($client, $bucket);
        $manifest = $this->localManifest($directory);
        $keys = $this->keys($client, $bucket);
        sort($keys, SORT_STRING);
        if ($keys !== array_map(strval(...), array_keys($manifest['objects']))) {
            throw new RuntimeException('recovery_object_count_mismatch');
        }
        foreach ($manifest['objects'] as $key => $object) {
            $body = $client->getObject(['Bucket' => $bucket, 'Key' => $key])['Body'];
            $digest = hash_init('sha256');
            $size = 0;
            while (! $body->eof()) {
                $bytes = $body->read(1048576);
                $size += strlen($bytes);
                hash_update($digest, $bytes);
            }
            if ($size !== $object['bytes'] || hash_final($digest) !== $object['sha256']) {
                throw new RuntimeException('recovery_object_checksum_mismatch');
            }
        }
    }

    /** @return array<string, mixed> */
    private function localManifest(string $directory): array
    {
        $manifest = json_decode(file_get_contents($directory.'/inventory.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['objects'] ?? null) || ! is_array($manifest['excluded_prefixes'] ?? null)) {
            throw new RuntimeException('recovery_invalid_object_manifest');
        }
        foreach ($manifest['objects'] as $key => $object) {
            if ($this->excluded($key, $manifest['excluded_prefixes']) || ($object['file'] ?? null) !== hash('sha256', $key) || ! isset($object['owner_generation'], $object['resource'], $object['bytes'], $object['sha256']) || is_link($directory.'/'.$object['file']) || filesize($directory.'/'.$object['file']) !== $object['bytes'] || hash_file('sha256', $directory.'/'.$object['file']) !== $object['sha256']) {
                throw new RuntimeException('recovery_invalid_object_manifest');
            }
        }

        return $manifest;
    }

    private function assertUnversioned(S3Client $client, string $bucket): void
    {
        if ($client->getBucketVersioning(['Bucket' => $bucket])['Status'] !== null) {
            throw new RuntimeException('recovery_unapproved_object_versions');
        }
    }

    /** @return list<string> */
    private function keys(S3Client $client, string $bucket): array
    {
        $keys = [];
        foreach ($client->getPaginator('ListObjectsV2', ['Bucket' => $bucket]) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $keys[] = $object['Key'];
            }
        }

        return $keys;
    }

    /** @param list<string> $prefixes */
    private function excluded(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix === '' || ! str_ends_with($prefix, '/')) {
                throw new RuntimeException('recovery_invalid_exclusion');
            }
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
