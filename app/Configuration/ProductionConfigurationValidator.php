<?php

namespace App\Configuration;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

final class ProductionConfigurationValidator
{
    /** @return list<string> */
    public function failures(): array
    {
        if (! app()->environment('production')) {
            return [];
        }

        $failures = [];
        $this->application($failures);
        $this->networkAndSession($failures);
        $this->persistence($failures);
        $this->queueOperations($failures);
        $this->notifications($failures);
        $this->administratorControls($failures);
        $this->providers($failures);

        return array_values(array_unique($failures));
    }

    public function assertReady(): void
    {
        $failures = $this->failures();
        if ($failures !== []) {
            throw new RuntimeException("Production configuration is invalid:\n- ".implode("\n- ", $failures));
        }
    }

    /** @param list<string> $failures */
    private function application(array &$failures): void
    {
        if (config('app.env') !== 'production') {
            $failures[] = 'APP_ENV must deliberately be set to production.';
        }
        if (config('app.debug') !== false) {
            $failures[] = 'Production APP_DEBUG must be false.';
        }

        $key = config('app.key');
        $decoded = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (! is_string($decoded) || ! Encrypter::supported($decoded, (string) config('app.cipher'))) {
            $failures[] = 'APP_KEY must contain a valid key for the configured application cipher.';
        }

        $url = config('app.url');
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : false;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $failures[] = 'APP_URL must be an explicit valid HTTPS URL.';
        } elseif (! is_string($host) || $this->developmentHost($host)) {
            $failures[] = 'APP_URL must not use a localhost or development-only hostname.';
        }
    }

    /** @param list<string> $failures */
    private function networkAndSession(array &$failures): void
    {
        if (config('session.secure') !== true) {
            $failures[] = 'SESSION_SECURE_COOKIE must be true in production.';
        }
        if (config('session.http_only') !== true) {
            $failures[] = 'SESSION_HTTP_ONLY must be true in production.';
        }
        if (! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $failures[] = 'SESSION_SAME_SITE must be lax or strict in production.';
        }
        if (config('session.driver') !== 'database') {
            $failures[] = 'SESSION_DRIVER=database is required in production.';
        }

        $hosts = config('production.trusted_hosts');
        $canonical = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_array($hosts) || $hosts === [] || ! is_string($canonical) || ! in_array($canonical, $hosts, true)) {
            $failures[] = 'TRUSTED_HOSTS must explicitly include the canonical APP_URL hostname.';
        } else {
            foreach ($hosts as $host) {
                if (! is_string($host) || str_contains($host, '*') || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                    $failures[] = 'TRUSTED_HOSTS may contain only explicit valid hostnames; wildcards are not permitted.';
                    break;
                }
            }
        }

        $setting = config('production.trusted_proxies_setting');
        $proxies = config('production.trusted_proxies');
        if (! is_string($setting) || $setting === '') {
            $failures[] = 'TRUSTED_PROXIES must be explicitly set to none or a comma-separated IP/CIDR allow-list.';
        } elseif ($setting !== 'none' && (! is_array($proxies) || $proxies === [] || in_array('*', $proxies, true))) {
            $failures[] = 'TRUSTED_PROXIES must not trust every proxy.';
        } elseif ($setting !== 'none') {
            foreach ($proxies as $proxy) {
                if (! is_string($proxy) || ! $this->validProxy($proxy)) {
                    $failures[] = 'TRUSTED_PROXIES entries must be valid IP addresses or CIDR ranges.';
                    break;
                }
            }
        }
        if (config('production.trusted_proxy_headers_setting') !== 'x-forwarded-for,x-forwarded-host,x-forwarded-port,x-forwarded-proto') {
            $failures[] = 'TRUSTED_PROXY_HEADERS must explicitly select the approved X-Forwarded header set.';
        }
    }

    /** @param list<string> $failures */
    private function persistence(array &$failures): void
    {
        if (config('database.default') !== 'mysql') {
            $failures[] = 'DB_CONNECTION=mysql is required in production.';
        }
        foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $key => $variable) {
            if ($this->blank(config("database.connections.mysql.$key"))) {
                $failures[] = "$variable is required for the production MySQL connection.";
            }
        }
        if (config('cache.default') !== 'database') {
            $failures[] = 'CACHE_STORE=database is required for shared durable production state.';
        }
        if (config('queue.default') !== 'database') {
            $failures[] = 'QUEUE_CONNECTION=database is required for durable production work.';
        }
        if (config('queue.failed.driver') !== 'database-uuids') {
            $failures[] = 'QUEUE_FAILED_DRIVER=database-uuids is required in production.';
        }

        $disk = config('production.storage.durable_disk');
        if ($disk !== 's3' || config('filesystems.default') !== $disk) {
            $failures[] = 'FILESYSTEM_DISK and PRODUCTION_DURABLE_DISK must explicitly select s3 in production.';
        }
        foreach (['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY', 'region' => 'AWS_DEFAULT_REGION', 'bucket' => 'AWS_BUCKET'] as $key => $variable) {
            if ($this->blank(config("filesystems.disks.s3.$key"))) {
                $failures[] = "$variable is required for durable production storage.";
            }
        }
    }

    /** @param list<string> $failures */
    private function queueOperations(array &$failures): void
    {
        if (config('queue-operations.enabled') !== true) {
            $failures[] = 'QUEUE_OPERATIONS_ENABLED must be true after the worker and scheduler topology is deployed.';
        }
        if (config('queue-operations.supervision') !== 'container') {
            $failures[] = 'QUEUE_SUPERVISION=container is the approved production process-supervision model.';
        }
        if (config('queue-operations.scheduler_enabled') !== true) {
            $failures[] = 'QUEUE_SCHEDULER_ENABLED must be true after the supervised UTC scheduler is deployed.';
        }

        $retryAfter = config('queue.connections.database.retry_after');
        $margin = config('queue-operations.retry_after_safety_margin_seconds');
        $workers = config('queue-operations.workers');
        $jobs = config('queue-operations.jobs');
        $queues = config('queue-operations.queues');
        if (! is_int($retryAfter) || ! is_int($margin) || ! is_array($workers) || ! is_array($jobs) || ! is_array($queues)) {
            $failures[] = 'Queue timeout and worker inventory configuration must be present.';

            return;
        }

        if ($queues !== ['security-notifications', 'default'] || $jobs === []) {
            $failures[] = 'Production queue topology must contain security-notifications then default.';
        }

        $maximumWindow = 0;
        foreach ($workers as $worker) {
            if (! is_array($worker) || ($worker['processes'] ?? null) !== 1) {
                $failures[] = 'Each approved production worker group must start with exactly one process.';

                continue;
            }
            $maximumWindow = max($maximumWindow, (int) ($worker['timeout'] ?? 0));
        }
        foreach ($jobs as $jobClass => $job) {
            if (! is_array($job) || ! in_array($job['failed_payload'] ?? null, ['metadata-only', 'personal'], true)) {
                $failures[] = 'Every queued job must have an approved failed-payload classification.';

                continue;
            }
            $maximumWindow = max($maximumWindow, (int) ($job['timeout'] ?? 0));
            $workerName = $job['worker'] ?? null;
            $queueName = $job['queue'] ?? null;
            if (! is_string($jobClass) || ! class_exists($jobClass)
                || ! is_string($workerName) || ! isset($workers[$workerName])
                || ! is_string($queueName) || ! in_array($queueName, $queues, true)
                || ! in_array($queueName, $workers[$workerName]['queues'] ?? [], true)) {
                $failures[] = 'Every queued job must map to an implemented class, approved queue and worker group.';
            }
        }

        if ($maximumWindow <= 0 || $retryAfter < $maximumWindow + $margin) {
            $failures[] = 'DB_QUEUE_RETRY_AFTER must exceed every worker/job timeout by the configured safety margin.';
        }
        if (config('queue.failed.table') !== 'failed_jobs' || config('queue.connections.database.table') !== 'jobs') {
            $failures[] = 'Production failed-job storage must use the failed_jobs table.';
        }
    }

    /** @param list<string> $failures */
    private function notifications(array &$failures): void
    {
        $mailer = config('administrator-security.notifications.mailer');
        $transport = is_string($mailer) ? config("mail.mailers.$mailer.transport") : null;
        if (! is_string($mailer) || ! in_array($transport, ['resend', 'smtp', 'ses', 'ses-v2', 'postmark'], true)) {
            $failures[] = 'ADMIN_SECURITY_MAILER must select an approved transactional delivery transport.';
        }
        if ($this->blank(config('administrator-security.notifications.provider'))) {
            $failures[] = 'ADMIN_SECURITY_PROVIDER must identify the production delivery provider.';
        }
        if (config('administrator-security.notifications.sender_verified') !== true) {
            $failures[] = 'ADMIN_SECURITY_SENDER_VERIFIED must be true after provider verification.';
        }
        if (config('administrator-security.notifications.queue') !== 'security-notifications') {
            $failures[] = 'ADMIN_SECURITY_QUEUE must be the monitored security-notifications queue.';
        }
        if ($this->blank(config('administrator-security.notifications.application_instance'))) {
            $failures[] = 'APP_INSTANCE must identify this production application instance.';
        }
        if (filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) === false) {
            $failures[] = 'MAIL_FROM_ADDRESS must be a valid verified sender address.';
        }

        if ($transport === 'smtp') {
            $smtp = config("mail.mailers.$mailer");
            $host = is_array($smtp) ? ($smtp['host'] ?? null) : null;
            if (! is_array($smtp) || ! in_array($smtp['scheme'] ?? null, ['tls', 'smtps'], true) || ! is_string($host) || $this->developmentHost($host) || $this->blank($smtp['username'] ?? null) || $this->blank($smtp['password'] ?? null)) {
                $failures[] = 'Production SMTP must use authenticated encrypted submission to a non-local relay.';
            }
        }
        if ($transport === 'resend' && $this->blank(config('services.resend.key'))) {
            $failures[] = 'RESEND_KEY is required for the selected security notification transport.';
        }
        if ($transport === 'postmark' && $this->blank(config('services.postmark.token'))) {
            $failures[] = 'POSTMARK_TOKEN is required for the selected security notification transport.';
        }
        if (in_array($transport, ['ses', 'ses-v2'], true) && ($this->blank(config('services.ses.key')) || $this->blank(config('services.ses.secret')))) {
            $failures[] = 'AWS mail credentials are required for the selected SES security notification transport.';
        }
    }

    /** @param list<string> $failures */
    private function administratorControls(array &$failures): void
    {
        if ($this->blank(config('administrator-security.verification.source_fingerprint_key'))) {
            $failures[] = 'ADMIN_SECURITY_FINGERPRINT_KEY is required for production second-factor throttling.';
        }
        if ($this->blank(config('administrator-security.totp.issuer'))) {
            $failures[] = 'ADMIN_TOTP_ISSUER is required for production authenticator enrollment.';
        }
        if (config('administrator-security.totp.enabled') !== true || config('administrator-security.totp.password_only_fallback') !== false) {
            $failures[] = 'Production administrator TOTP must be enabled with password-only fallback disabled.';
        }
        $this->lifecycle($failures, 'bootstrap', 'target_email');
        $this->lifecycle($failures, 'break_glass', 'replacement_email');
    }

    /** @param list<string> $failures */
    private function lifecycle(array &$failures, string $control, string $targetKey): void
    {
        $configuration = config("administrator-security.lifecycle.$control");
        if (! is_array($configuration) || ($configuration['enabled'] ?? false) !== true) {
            return;
        }
        if (($configuration['expected_environment'] ?? null) !== 'production') {
            $failures[] = "Enabled administrator $control must be explicitly bound to production.";
        }
        if ($this->blank($configuration['operator_reference'] ?? null)) {
            $failures[] = "Enabled administrator $control requires a non-secret operator reference.";
        }
        if (filter_var($configuration[$targetKey] ?? null, FILTER_VALIDATE_EMAIL) === false) {
            $failures[] = "Enabled administrator $control requires a valid configured target constraint.";
        }
    }

    /** @param list<string> $failures */
    private function providers(array &$failures): void
    {
        $userAgent = config('services.openfoodfacts.user_agent');
        if (! is_string($userAgent) || str_contains(strtolower($userAgent), 'development') || str_contains(strtolower($userAgent), 'localhost') || ! str_contains($userAgent, '(')) {
            $failures[] = 'OPENFOODFACTS_USER_AGENT must identify the production application and provide contact metadata.';
        }

        if (config('production.imports.enabled') === true) {
            $this->requiredFeatureSettings($failures, 'imports', ['transient_disk', 'parser_version', 'queue']);
            if (config('production.imports.transient_disk') !== config('production.storage.durable_disk')) {
                $failures[] = 'Enabled recipe imports require the configured private durable transient disk.';
            }
            if (config('production.imports.formats') !== ['txt', 'md', 'html']
                || ! $this->within(config('production.imports.max_upload_bytes'), 1, 2097152)
                || ! $this->within(config('production.imports.max_url_length'), 1, 2048)
                || ! $this->within(config('production.imports.max_redirects'), 0, 5)
                || ! $this->within(config('production.imports.connect_timeout_seconds'), 1, 3)
                || ! $this->within(config('production.imports.timeout_seconds'), 1, 15)
                || ! $this->within(config('production.imports.attempts'), 1, 3)
                || config('production.imports.backoff_seconds') !== ['10', '60']
                || ! $this->within(config('production.imports.concurrency'), 1, 2)
                || ! $this->within(config('production.imports.per_user_per_hour'), 1, 10)
                || ! $this->within(config('production.imports.cleanup_hours'), 1, 24)) {
                $failures[] = 'Enabled recipe imports exceed the approved formats or resource bounds.';
            }
        }
        if (config('production.ocr.enabled') === true) {
            $this->requiredFeatureSettings($failures, 'ocr', ['tesseract_version', 'language', 'heic_decoder_version', 'preprocessing_version', 'queue', 'transient_disk']);
            if (config('production.ocr.tesseract_version') !== '5' || config('production.ocr.language') !== 'eng') {
                $failures[] = 'Enabled OCR requires pinned Tesseract 5 and English trained data.';
            }
            if (config('production.ocr.transient_disk') !== config('production.storage.durable_disk')) {
                $failures[] = 'Enabled OCR requires the configured private durable transient disk.';
            }
            if (! $this->within(config('production.ocr.max_upload_bytes'), 1, 20971520)
                || ! $this->within(config('production.ocr.max_megapixels'), 1, 50)
                || (int) config('production.ocr.max_images') !== 1
                || ! $this->within(config('production.ocr.attempts'), 1, 3)
                || ! $this->within(config('production.ocr.timeout_seconds'), 1, 60)
                || ! $this->within(config('production.ocr.concurrency'), 1, 2)
                || ! $this->within(config('production.ocr.cleanup_hours'), 1, 24)) {
                $failures[] = 'Enabled OCR exceeds the approved input, retry, timeout, concurrency, or cleanup bounds.';
            }
        }
        if (config('production.ocr.google.enabled') === true) {
            $this->requiredFeatureSettings($failures, 'ocr.google', ['project_id', 'endpoint', 'processor_id', 'model_version', 'credentials_path']);
            if (config('production.ocr.enabled') !== true || config('production.ocr.google.location') !== 'eu') {
                $failures[] = 'Google OCR fallback requires local OCR and the approved eu location.';
            }
            if ((int) config('production.ocr.google.monthly_page_quota') < 1 || (int) config('production.ocr.google.monthly_budget_minor') < 1) {
                $failures[] = 'Google OCR fallback requires positive quota and budget safeguards.';
            }
            if (! $this->within(config('production.ocr.google.timeout_seconds'), 1, 30)) {
                $failures[] = 'Google OCR fallback timeout must be between 1 and 30 seconds.';
            }
        }
    }

    /** @param list<string> $failures @param list<string> $keys */
    private function requiredFeatureSettings(array &$failures, string $feature, array $keys): void
    {
        foreach ($keys as $key) {
            if ($this->blank(config("production.$feature.$key"))) {
                $failures[] = "Enabled $feature requires $key configuration.";
            }
        }
    }

    private function within(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    private function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }

    private function validProxy(string $proxy): bool
    {
        [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($prefix === null) {
            return true;
        }

        $maximum = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return ctype_digit($prefix) && (int) $prefix <= $maximum;
    }

    private function developmentHost(string $host): bool
    {
        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '::1', 'mailpit', 'mailhog'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    }
}
