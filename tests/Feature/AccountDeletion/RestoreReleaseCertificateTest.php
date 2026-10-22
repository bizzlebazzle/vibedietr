<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\JournalUnavailable;
use App\Domain\AccountDeletion\RestoreReleaseCertificate;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestoreReleaseCertificateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        config(['erasure.privacy_operator' => 'synthetic-release-operator', 'erasure.operator_environment' => 'testing',
            'erasure.restore_target' => (string) Str::ulid(), 'erasure.restore_set' => str_repeat('a', 64),
            'erasure.restore_manifest_digest' => str_repeat('b', 64), 'erasure.installation' => 'synthetic-installation',
            'observability.release' => 'synthetic-release',
            'erasure.release_signing_key' => 'base64:'.base64_encode(sodium_crypto_sign_secretkey($pair)),
            'erasure.release_public_key' => 'base64:'.base64_encode(sodium_crypto_sign_publickey($pair))]);
    }

    public function test_verification_needs_only_the_public_key_and_is_bound_to_the_target(): void
    {
        $service = app(RestoreReleaseCertificate::class);
        $proof = ['phase' => 'released', 'context' => $service->context()];
        $certificate = $service->sign($proof);
        config(['erasure.release_signing_key' => null, 'erasure.privacy_operator' => null]);
        $this->assertSame($proof, $service->verify($certificate));
        config(['erasure.restore_target' => (string) Str::ulid()]);
        $this->expectException(JournalUnavailable::class);
        $service->verify($certificate);
    }

    public function test_a_preparation_signature_cannot_authorize_serving(): void
    {
        $service = app(RestoreReleaseCertificate::class);
        $prepared = $service->sign(['phase' => 'prepared', 'context' => $service->context()]);
        $this->expectException(JournalUnavailable::class);
        $service->verify($prepared);
    }

    public function test_missing_certificate_blocks_login_downloads_and_health_before_application_processing(): void
    {
        config(['erasure.release_certificate' => null]);
        $this->get('/login')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/health/ready')->assertStatus(503);
        $this->get('/account-exports/'.Str::ulid().'/download')->assertStatus(503);
    }

    public function test_altered_signed_payload_is_rejected(): void
    {
        $service = app(RestoreReleaseCertificate::class);
        $certificate = $service->sign(['phase' => 'released', 'context' => $service->context()]);
        $certificate['payload'] .= ' ';
        $this->expectException(JournalUnavailable::class);
        $service->verify($certificate);
    }
}
