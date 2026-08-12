<?php

namespace App\Audit;

use App\Audit\Enums\AuditAction;
use App\Domain\Nutrition\NutrientRegistry;
use InvalidArgumentException;

final class AuditPayloadValidator
{
    private const MAX_DEPTH = 2;

    private const MAX_KEYS = 12;

    private const MAX_STRING_LENGTH = 64;

    private const MAX_LIST_ITEMS = 10;

    private const MAX_ENCODED_BYTES = 2048;

    private const PROHIBITED_KEY_PARTS = [
        'password',
        'hash',
        'credential',
        'token',
        'secret',
        'authorization',
        'cookie',
        'session',
        'ip_address',
        'user_agent',
        'email',
        'full_name',
        'request_body',
        'request_data',
        'recipe_content',
        'ingredient_text',
        'instruction',
        'diary',
        'nutrition_target',
        'ocr',
        'upload_content',
        'file_content',
        'evidence_content',
        'environment_value',
        'command_arguments',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(AuditAction $action, array $payload): array
    {
        $this->rejectDangerousContent($payload);
        $this->assertBounds($payload);

        $validated = match ($action) {
            AuditAction::AdministratorBootstrapCompleted => $this->validateBootstrap(
                $payload,
                completed: true,
            ),
            AuditAction::AdministratorBootstrapRefused => $this->validateBootstrap(
                $payload,
                completed: false,
            ),
            AuditAction::CatalogueProposalApproved => $this->validateCatalogueApproval($payload),
            AuditAction::RecipeNutritionOverrideApplied => $this->validateNutritionOverride($payload),
            AuditAction::PlanSnapshotRecorded => $this->validatePlanSnapshot($payload),
            AuditAction::AccountAnonymizationCompleted => $this->validateAnonymization($payload),
            AuditAction::SecuritySecondFactorEvent,
            AuditAction::SecurityNotificationEvent => $this->validateSecurityEvent($payload),
        };

        ksort($validated);

        return $validated;
    }

    /** @param array<string, mixed> $payload */
    private function validateBootstrap(array $payload, bool $completed): array
    {
        $allowed = [
            'administrator_count_before',
            'application_instance_reference',
            'bootstrap_marker_previously_set',
            'configured_target_match',
            'environment_category',
            'operation_version',
            'outcome',
            'previous_privilege_state',
            'refusal_reason_code',
            'resulting_privilege_state',
        ];
        $required = [
            'administrator_count_before',
            'bootstrap_marker_previously_set',
            'configured_target_match',
            'environment_category',
            'outcome',
            'previous_privilege_state',
            'resulting_privilege_state',
        ];

        if (! $completed) {
            $required[] = 'refusal_reason_code';
        }

        $this->assertShape($payload, $allowed, $required);
        $this->assertInteger($payload, 'administrator_count_before', minimum: 0, maximum: 1000);
        $this->assertBoolean($payload, 'bootstrap_marker_previously_set');
        $this->assertBoolean($payload, 'configured_target_match');
        $this->assertEnum($payload, 'environment_category', ['production', 'staging', 'local', 'testing']);
        $this->assertEnum($payload, 'outcome', [$completed ? 'completed' : 'refused']);
        $this->assertEnum($payload, 'previous_privilege_state', ['ordinary']);
        $this->assertEnum(
            $payload,
            'resulting_privilege_state',
            [$completed ? 'administrator' : 'ordinary'],
        );

        foreach (['application_instance_reference', 'operation_version'] as $field) {
            if (array_key_exists($field, $payload)) {
                AuditReferenceValidator::validate($payload[$field], $field);
            }
        }

        if (array_key_exists('refusal_reason_code', $payload)) {
            $this->assertEnum($payload, 'refusal_reason_code', [
                'already_bootstrapped',
                'administrator_exists',
                'audit_unavailable',
                'configuration_mismatch',
                'environment_mismatch',
                'operator_declined',
                'target_ineligible',
            ]);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validateCatalogueApproval(array $payload): array
    {
        $this->assertShape($payload, ['decision_code', 'outcome'], ['decision_code', 'outcome']);
        $this->assertEnum($payload, 'decision_code', ['approved_as_submitted', 'approved_with_new_version']);
        $this->assertEnum($payload, 'outcome', ['approved']);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validateNutritionOverride(array $payload): array
    {
        $this->assertShape($payload, ['changed_nutrients', 'outcome'], ['changed_nutrients', 'outcome']);
        $this->assertEnum($payload, 'outcome', ['applied']);
        $this->assertStringList($payload, 'changed_nutrients', NutrientRegistry::stableIdentifiers());

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validatePlanSnapshot(array $payload): array
    {
        $this->assertShape($payload, ['outcome', 'snapshot_kind'], ['outcome', 'snapshot_kind']);
        $this->assertEnum($payload, 'outcome', ['recorded']);
        $this->assertEnum($payload, 'snapshot_kind', ['planned', 'consumed']);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validateAnonymization(array $payload): array
    {
        $this->assertShape($payload, ['anonymization_scope', 'outcome'], ['anonymization_scope', 'outcome']);
        $this->assertEnum($payload, 'outcome', ['completed']);
        $this->assertEnum($payload, 'anonymization_scope', [
            'account_final_purge',
            'catalogue_contributor',
            'public_content_owner',
        ]);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validateSecurityEvent(array $payload): array
    {
        $this->assertShape(
            $payload,
            ['event', 'operation', 'outcome', 'reason_code', 'recipient_category', 'delivery_status'],
            ['event', 'outcome'],
        );
        foreach ($payload as $field => $value) {
            if (! is_string($value) || $value === '' || strlen($value) > self::MAX_STRING_LENGTH) {
                throw new InvalidArgumentException("Invalid audit payload value for $field.");
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    private function assertShape(array $payload, array $allowed, array $required): void
    {
        $unknown = array_diff(array_keys($payload), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown audit payload field: '.reset($unknown));
        }

        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("Required audit payload field is missing: $field");
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertEnum(array $payload, string $field, array $allowed): void
    {
        if (! is_string($payload[$field] ?? null) || ! in_array($payload[$field], $allowed, true)) {
            throw new InvalidArgumentException("Invalid audit payload value for $field.");
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertBoolean(array $payload, string $field): void
    {
        if (! is_bool($payload[$field] ?? null)) {
            throw new InvalidArgumentException("Audit payload field $field must be boolean.");
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertInteger(
        array $payload,
        string $field,
        int $minimum,
        int $maximum,
    ): void {
        $value = $payload[$field] ?? null;

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Audit payload field $field is outside its allowed range.");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowedValues
     */
    private function assertStringList(array $payload, string $field, array $allowedValues): void
    {
        $values = $payload[$field] ?? null;

        if (! is_array($values) || ! array_is_list($values) || $values === [] || count($values) > self::MAX_LIST_ITEMS) {
            throw new InvalidArgumentException("Audit payload field $field must be a bounded non-empty list.");
        }

        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowedValues, true)) {
                throw new InvalidArgumentException("Invalid audit payload list value for $field.");
            }
        }

        if (count(array_unique($values)) !== count($values)) {
            throw new InvalidArgumentException("Audit payload field $field cannot contain duplicates.");
        }
    }

    private function rejectDangerousContent(mixed $value, ?string $key = null): void
    {
        if ($key !== null) {
            $normalizedKey = strtolower($key);

            foreach (self::PROHIBITED_KEY_PARTS as $prohibited) {
                if (str_contains($normalizedKey, $prohibited)) {
                    throw new InvalidArgumentException("Prohibited audit payload field: $key");
                }
            }
        }

        if (is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Raw IP addresses are prohibited in audit payloads.');
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $childKey => $childValue) {
            $this->rejectDangerousContent($childValue, is_string($childKey) ? $childKey : null);
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertBounds(array $payload): void
    {
        if (count($payload) > self::MAX_KEYS) {
            throw new InvalidArgumentException('Audit payload has too many fields.');
        }

        $this->assertValueBounds($payload, depth: 1);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw new InvalidArgumentException('Audit payload exceeds the maximum encoded size.');
        }
    }

    private function assertValueBounds(mixed $value, int $depth): void
    {
        if (is_string($value) && strlen($value) > self::MAX_STRING_LENGTH) {
            throw new InvalidArgumentException('Audit payload contains an oversized string.');
        }

        if (! is_array($value)) {
            return;
        }

        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Audit payload exceeds the maximum depth.');
        }

        if (count($value) > self::MAX_KEYS) {
            throw new InvalidArgumentException('Audit payload contains too many values.');
        }

        foreach ($value as $child) {
            $this->assertValueBounds($child, $depth + 1);
        }
    }
}
