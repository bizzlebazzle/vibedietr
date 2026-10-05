<?php

use App\Operations\PrivateObjectRecovery;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('RECOVERY_DRILL') !== 'yes' || ! str_starts_with((string) config('database.connections.mysql.host'), 'dep06-')) {
    throw new RuntimeException('Synthetic isolated drill required');
}
$disk = config('filesystems.disks.s3');
$client = new S3Client([
    'version' => 'latest', 'region' => $disk['region'], 'endpoint' => $disk['endpoint'], 'use_path_style_endpoint' => true,
    'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']], 'request_checksum_calculation' => 'when_required',
]);
$recovery = new PrivateObjectRecovery;
$expect = function (callable $operation, string $code): void {
    try {
        $operation();
    } catch (RuntimeException $error) {
        if ($error->getMessage() === $code) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Unsafe object operation unexpectedly succeeded');
};
$expect(fn () => $recovery->capture($client, $disk['bucket'], '/drill/unmapped-objects', [], ['inputs/', 'canonical/', 'exports/']), 'recovery_object_ownership_missing');
Storage::disk('s3')->put('durable/owner', 'Tampered synthetic bytes');
$expect(fn () => $recovery->verify($client, $disk['bucket'], '/drill/quarantine/work/set/objects'), 'recovery_object_checksum_mismatch');
Storage::disk('s3')->put('durable/owner', 'Synthetic owner durable bytes');
Storage::disk('s3')->delete('durable/other');
$expect(fn () => $recovery->verify($client, $disk['bucket'], '/drill/quarantine/work/set/objects'), 'recovery_object_count_mismatch');
Storage::disk('s3')->put('durable/other', 'Synthetic other durable bytes');
$recovery->verify($client, $disk['bucket'], '/drill/quarantine/work/set/objects');
$response = (new Client)->get($disk['endpoint'].'/'.$disk['bucket'].'/durable/owner', ['http_errors' => false]);
if ($response->getStatusCode() < 400 || str_contains((string) $response->getBody(), 'Synthetic owner durable bytes')) {
    throw new RuntimeException('Anonymous private object access unexpectedly succeeded');
}
echo "Unmapped objects, tampering, missing objects and anonymous access rejected.\n";
