<?php

namespace Tests\Feature;

use App\Configuration\ProductionConfigurationValidator;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

class ProductionConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useValidProductionConfiguration();
    }

    public function test_valid_production_configuration_passes(): void
    {
        $this->assertSame([], $this->validator()->failures());
        $this->assertSame(0, Artisan::call('app:production-check'));
        $this->assertStringContainsString('Production configuration is valid', Artisan::output());
    }

    public function test_required_production_controls_fail_independently(): void
    {
        $cases = [
            ['app.key', '', 'APP_KEY'],
            ['app.debug', true, 'APP_DEBUG'],
            ['app.url', '', 'APP_URL'],
            ['app.url', 'http://app.example.com', 'HTTPS'],
            ['app.url', 'https://localhost', 'development-only'],
            ['session.secure', false, 'SESSION_SECURE_COOKIE'],
            ['session.http_only', false, 'SESSION_HTTP_ONLY'],
            ['session.same_site', 'none', 'SESSION_SAME_SITE'],
            ['session.driver', 'file', 'SESSION_DRIVER'],
            ['production.trusted_hosts', [], 'TRUSTED_HOSTS'],
            ['production.trusted_hosts', ['*'], 'TRUSTED_HOSTS'],
            ['production.trusted_proxies_setting', null, 'TRUSTED_PROXIES'],
            ['production.trusted_proxies_setting', '*', 'trust every proxy'],
            ['production.trusted_proxy_headers_setting', null, 'TRUSTED_PROXY_HEADERS'],
            ['database.default', 'sqlite', 'DB_CONNECTION'],
            ['database.connections.mysql.host', '', 'DB_HOST'],
            ['database.connections.mysql.database', '', 'DB_DATABASE'],
            ['database.connections.mysql.username', '', 'DB_USERNAME'],
            ['database.connections.mysql.password', '', 'DB_PASSWORD'],
            ['cache.default', 'file', 'CACHE_STORE'],
            ['queue.default', 'sync', 'QUEUE_CONNECTION'],
            ['queue-operations.enabled', false, 'QUEUE_OPERATIONS_ENABLED'],
            ['queue-operations.supervision', 'local', 'QUEUE_SUPERVISION'],
            ['queue-operations.scheduler_enabled', false, 'QUEUE_SCHEDULER_ENABLED'],
            ['observability.adapter', 'local', 'OBSERVABILITY_ADAPTER'],
            ['observability.release', 'development', 'OBSERVABILITY_RELEASE'],
            ['queue.connections.database.retry_after', 89, 'DB_QUEUE_RETRY_AFTER'],
            ['queue-operations.queues', ['default'], 'queue topology'],
            ['queue.failed.driver', 'null', 'QUEUE_FAILED_DRIVER'],
            ['filesystems.default', 'local', 'FILESYSTEM_DISK'],
            ['filesystems.disks.s3.bucket', '', 'AWS_BUCKET'],
            ['administrator-security.notifications.mailer', 'log', 'ADMIN_SECURITY_MAILER'],
            ['administrator-security.notifications.provider', null, 'ADMIN_SECURITY_PROVIDER'],
            ['administrator-security.notifications.sender_verified', false, 'ADMIN_SECURITY_SENDER_VERIFIED'],
            ['mail.from.address', 'invalid', 'MAIL_FROM_ADDRESS'],
            ['mail.mailers.smtp.scheme', null, 'authenticated encrypted'],
            ['mail.mailers.smtp.host', 'mailpit', 'non-local relay'],
            ['mail.mailers.smtp.password', '', 'authenticated encrypted'],
            ['administrator-security.verification.source_fingerprint_key', '', 'ADMIN_SECURITY_FINGERPRINT_KEY'],
            ['administrator-security.totp.issuer', '', 'ADMIN_TOTP_ISSUER'],
            ['administrator-security.totp.enabled', false, 'TOTP'],
            ['administrator-security.totp.password_only_fallback', true, 'password-only'],
            ['services.openfoodfacts.user_agent', 'VibeDietr/development (http://localhost)', 'OPENFOODFACTS_USER_AGENT'],
        ];

        foreach ($cases as [$key, $value, $expected]) {
            $this->useValidProductionConfiguration();
            config([$key => $value]);

            $this->assertStringContainsString($expected, implode("\n", $this->validator()->failures()), $key);
        }
    }

    public function test_trusted_hosts_are_enforced_as_exact_patterns(): void
    {
        config(['session.driver' => 'array']);
        $this->get('https://app.example.com')->assertOk();
        $this->get('https://appXexample.com')->assertStatus(400);
        SymfonyRequest::setTrustedHosts([]);
    }

    public function test_trusted_proxies_require_valid_ip_or_cidr_entries(): void
    {
        config([
            'production.trusted_proxies_setting' => 'proxy.invalid',
            'production.trusted_proxies' => ['proxy.invalid'],
        ]);
        $this->assertStringContainsString('valid IP addresses or CIDR ranges', implode(' ', $this->validator()->failures()));

        config([
            'production.trusted_proxies_setting' => '10.0.0.0/8,2001:db8::/32',
            'production.trusted_proxies' => ['10.0.0.0/8', '2001:db8::/32'],
        ]);
        $this->assertSame([], $this->validator()->failures());
    }

    public function test_mail_provider_credentials_are_required_only_for_the_selected_transport(): void
    {
        config([
            'administrator-security.notifications.mailer' => 'resend',
            'mail.mailers.resend.transport' => 'resend',
            'services.resend.key' => null,
        ]);
        $this->assertStringContainsString('RESEND_KEY', implode(' ', $this->validator()->failures()));

        config(['services.resend.key' => 'synthetic-resend-secret']);
        $this->assertSame([], $this->validator()->failures());
    }

    public function test_bootstrap_and_break_glass_are_disabled_by_default_and_incomplete_enablement_fails(): void
    {
        $this->assertFalse(config('administrator-security.lifecycle.bootstrap.enabled'));
        $this->assertFalse(config('administrator-security.lifecycle.break_glass.enabled'));
        $this->assertSame([], $this->validator()->failures());

        config([
            'administrator-security.lifecycle.bootstrap.enabled' => true,
            'administrator-security.lifecycle.bootstrap.expected_environment' => 'local',
            'administrator-security.lifecycle.bootstrap.target_email' => null,
            'administrator-security.lifecycle.bootstrap.operator_reference' => null,
        ]);
        $bootstrap = implode(' ', $this->validator()->failures());
        $this->assertStringContainsString('bound to production', $bootstrap);
        $this->assertStringContainsString('operator reference', $bootstrap);
        $this->assertStringContainsString('target constraint', $bootstrap);

        $this->useValidProductionConfiguration();
        config([
            'administrator-security.lifecycle.break_glass.enabled' => true,
            'administrator-security.lifecycle.break_glass.expected_environment' => null,
            'administrator-security.lifecycle.break_glass.replacement_email' => null,
            'administrator-security.lifecycle.break_glass.operator_reference' => null,
        ]);
        $this->assertNotEmpty($this->validator()->failures());
    }

    public function test_optional_import_and_ocr_providers_are_feature_scoped(): void
    {
        $this->assertSame([], $this->validator()->failures());

        config(['production.imports.enabled' => true]);
        $this->assertStringContainsString('Enabled imports requires', implode(' ', $this->validator()->failures()));

        $this->useValidProductionConfiguration();
        config(['production.ocr.google.enabled' => true]);
        $google = implode(' ', $this->validator()->failures());
        $this->assertStringContainsString('Enabled ocr.google requires', $google);
        $this->assertStringContainsString('approved eu location', $google);
        $this->assertStringContainsString('quota and budget', $google);
    }

    public function test_complete_enabled_import_ocr_and_google_configuration_passes_within_bounds(): void
    {
        config([
            'production.imports.enabled' => true,
            'production.imports.transient_disk' => 's3',
            'production.imports.parser_version' => 'parser-v1',
            'production.ocr.enabled' => true,
            'production.ocr.tesseract_version' => '5',
            'production.ocr.language' => 'eng',
            'production.ocr.heic_decoder_version' => 'decoder-v1',
            'production.ocr.preprocessing_version' => 'preprocess-v1',
            'production.ocr.transient_disk' => 's3',
            'production.ocr.google.enabled' => true,
            'production.ocr.google.project_id' => 'synthetic-project',
            'production.ocr.google.location' => 'eu',
            'production.ocr.google.endpoint' => 'eu-documentai.googleapis.com',
            'production.ocr.google.processor_id' => 'synthetic-processor',
            'production.ocr.google.model_version' => 'stable-v1',
            'production.ocr.google.credentials_path' => '/run/secrets/google-credentials',
            'production.ocr.google.monthly_page_quota' => 100,
            'production.ocr.google.monthly_budget_minor' => 1000,
        ]);
        $this->assertSame([], $this->validator()->failures());

        config(['production.ocr.max_images' => 2]);
        $this->assertStringContainsString('approved input', implode(' ', $this->validator()->failures()));
    }

    public function test_validation_output_and_exceptions_never_reveal_secret_values(): void
    {
        $secrets = [
            'distinctive-app-secret',
            'distinctive-database-secret',
            'distinctive-mail-secret',
            'distinctive-storage-secret',
            'distinctive-fingerprint-secret',
        ];
        config([
            'app.key' => $secrets[0],
            'database.connections.mysql.password' => $secrets[1],
            'mail.mailers.smtp.password' => $secrets[2],
            'filesystems.disks.s3.secret' => $secrets[3],
            'administrator-security.verification.source_fingerprint_key' => $secrets[4],
            'app.debug' => true,
        ]);

        Artisan::call('app:production-check');
        $output = Artisan::output();
        $exception = '';
        try {
            $this->validator()->assertReady();
        } catch (\RuntimeException $caught) {
            $exception = $caught->getMessage();
        }

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $output);
            $this->assertStringNotContainsString($secret, $exception);
        }
    }

    public function test_runtime_validator_has_no_uncached_environment_reads(): void
    {
        $source = file_get_contents(app_path('Configuration/ProductionConfigurationValidator.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('env(', $source);
    }

    public function test_env_example_represents_required_production_variables_without_secret_values(): void
    {
        $environment = file_get_contents(base_path('.env.example'));
        $this->assertIsString($environment);

        foreach ([
            'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'TRUSTED_HOSTS',
            'TRUSTED_PROXIES', 'TRUSTED_PROXY_HEADERS', 'DB_CONNECTION',
            'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CACHE_STORE',
            'QUEUE_CONNECTION', 'QUEUE_FAILED_DRIVER', 'FILESYSTEM_DISK',
            'DB_QUEUE_RETRY_AFTER', 'QUEUE_OPERATIONS_ENABLED', 'QUEUE_SUPERVISION', 'QUEUE_SCHEDULER_ENABLED',
            'PRODUCTION_DURABLE_DISK', 'SESSION_DRIVER', 'SESSION_SECURE_COOKIE',
            'ADMIN_SECURITY_FINGERPRINT_KEY', 'ADMIN_TOTP_ISSUER',
            'ADMIN_SECURITY_MAILER', 'ADMIN_SECURITY_PROVIDER', 'MAIL_FROM_ADDRESS',
            'OPENFOODFACTS_USER_AGENT', 'RECIPE_IMPORTS_ENABLED', 'OCR_ENABLED',
            'OCR_GOOGLE_FALLBACK_ENABLED',
        ] as $variable) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($variable, '/').'=/m', $environment, $variable);
        }

        foreach (['APP_KEY', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'RESEND_KEY', 'POSTMARK_TOKEN', 'ADMIN_SECURITY_FINGERPRINT_KEY', 'GOOGLE_APPLICATION_CREDENTIALS'] as $secret) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($secret, '/').'=$/m', $environment, $secret);
        }
    }

    private function validator(): ProductionConfigurationValidator
    {
        return app(ProductionConfigurationValidator::class);
    }

    private function useValidProductionConfiguration(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://app.example.com',
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'session.driver' => 'database',
            'production.trusted_hosts' => ['app.example.com'],
            'production.trusted_proxies_setting' => 'none',
            'production.trusted_proxies' => [],
            'production.trusted_proxy_headers_setting' => 'x-forwarded-for,x-forwarded-host,x-forwarded-port,x-forwarded-proto',
            'database.default' => 'mysql',
            'database.connections.mysql.host' => 'database.internal',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'vibedietr',
            'database.connections.mysql.username' => 'vibedietr',
            'database.connections.mysql.password' => 'synthetic-database-secret',
            'cache.default' => 'database',
            'queue.default' => 'database',
            'queue.failed.driver' => 'database-uuids',
            'queue-operations.enabled' => true,
            'queue-operations.supervision' => 'container',
            'queue-operations.scheduler_enabled' => true,
            'production.storage.durable_disk' => 's3',
            'observability.adapter' => 'platform',
            'observability.release' => '2026.08.27-dep05',
            'observability.alert_recipient_role' => 'primary_administrator',
            'filesystems.default' => 's3',
            'filesystems.disks.s3.key' => 'synthetic-storage-key',
            'filesystems.disks.s3.secret' => 'synthetic-storage-secret',
            'filesystems.disks.s3.region' => 'eu-west-2',
            'filesystems.disks.s3.bucket' => 'vibedietr-production',
            'administrator-security.notifications.mailer' => 'smtp',
            'administrator-security.notifications.provider' => 'qualifying-smtp',
            'administrator-security.notifications.sender_verified' => true,
            'administrator-security.notifications.queue' => 'security-notifications',
            'administrator-security.notifications.application_instance' => 'production-a',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => 'tls',
            'mail.mailers.smtp.host' => 'smtp.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'synthetic-user',
            'mail.mailers.smtp.password' => 'synthetic-mail-secret',
            'mail.from.address' => 'security@example.com',
            'administrator-security.verification.source_fingerprint_key' => 'synthetic-fingerprint-secret',
            'administrator-security.totp.enabled' => true,
            'administrator-security.totp.issuer' => 'VibeDietr',
            'administrator-security.totp.password_only_fallback' => false,
            'administrator-security.lifecycle.bootstrap.enabled' => false,
            'administrator-security.lifecycle.break_glass.enabled' => false,
            'services.openfoodfacts.user_agent' => 'VibeDietr/1.0 (https://app.example.com/contact)',
            'production.imports.enabled' => false,
            'production.ocr.enabled' => false,
            'production.ocr.google.enabled' => false,
        ]);
    }
}
