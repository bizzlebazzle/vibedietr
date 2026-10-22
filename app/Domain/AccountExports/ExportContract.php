<?php

namespace App\Domain\AccountExports;

use App\Queue\Exceptions\NonRetryableJobException;
use RuntimeException;
use stdClass;

/** The released schema is also the projection allowlist. Never serialize a model graph. */
final class ExportContract
{
    private array $schema;

    public function __construct()
    {
        $this->schema = json_decode(file_get_contents(resource_path('account-export/1.1.0/account-export-1.1.0.schema.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function project(string $definition, mixed $value, bool $databaseRow = false): mixed
    {
        return $this->apply($this->schema['$defs'][$definition], $value, $databaseRow);
    }

    private function apply(array $schema, mixed $value, bool $databaseRow = false): mixed
    {
        if (is_array($schema['type'] ?? null)) {
            return $this->apply(['anyOf' => array_map(fn ($type) => [...$schema, 'type' => $type], $schema['type'])], $value, $databaseRow);
        }
        if (isset($schema['$ref'])) {
            return $this->project(basename($schema['$ref']), $value, $databaseRow);
        }
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                try {
                    return $this->apply($option, $value, $databaseRow);
                } catch (RuntimeException) {
                    // Try only explicitly declared alternatives.
                }
            }
            throw new NonRetryableJobException('export_contract_shape');
        }
        if (isset($schema['const'])) {
            if ($value !== $schema['const']) {
                throw new NonRetryableJobException('export_contract_constant');
            }

            return $value;
        }
        if (isset($schema['enum'])) {
            if (! in_array($value, $schema['enum'], true)) {
                throw new NonRetryableJobException('export_contract_enum');
            }

            return $value;
        }
        if (($schema['type'] ?? null) === 'null' && $value === null) {
            return null;
        }
        if ($value === null) {
            throw new NonRetryableJobException('export_contract_null');
        }
        switch ($schema['type'] ?? '') {
            case 'object':
                if ($value instanceof stdClass) {
                    $value = (array) $value;
                }
                if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                    throw new NonRetryableJobException('export_contract_object');
                }
                if (! $databaseRow && array_diff(array_keys($value), array_keys($schema['properties']), $schema['x-omitted'] ?? []) !== []) {
                    throw new NonRetryableJobException('export_contract_unclassified_field');
                }
                $result = [];
                foreach ($schema['properties'] as $key => $child) {
                    if (array_key_exists($key, $value)) {
                        $result[$key] = $this->apply($child, $value[$key]);
                    } elseif (in_array($key, $schema['required'], true)) {
                        throw new NonRetryableJobException('export_contract_required');
                    }
                }

                return (object) $result;
            case 'array':
                if (! is_array($value) || ! array_is_list($value)) {
                    throw new NonRetryableJobException('export_contract_array');
                }

                return array_map(fn ($item) => $this->apply($schema['items'], $item), $value);
            case 'string':
                if (isset($schema['x-id-prefix'])) {
                    $prefix = $schema['x-id-prefix'].':';
                    $value = str_starts_with((string) $value, $prefix) ? (string) $value : $prefix.$value;
                } elseif (isset($schema['x-instant'])) {
                    $value = preg_replace('/\+00:00$/', 'Z', str_replace(' ', 'T', (string) $value));
                    if (! str_ends_with($value, 'Z')) {
                        $value .= 'Z';
                    }
                } elseif (isset($schema['x-decimal']) && is_int($value)) {
                    $value = (string) $value;
                }
                if (! is_string($value) || (isset($schema['pattern']) && preg_match('~'.$schema['pattern'].'~D', $value) !== 1)) {
                    throw new NonRetryableJobException('export_contract_string');
                }

                return $value;
            case 'integer':
                if (is_string($value) && preg_match('/^-?[0-9]{1,9}$/D', $value)) {
                    $value = (int) $value;
                }
                if (! is_int($value) || $value < ($schema['minimum'] ?? PHP_INT_MIN)) {
                    throw new NonRetryableJobException('export_contract_integer');
                }

                return $value;
            case 'boolean':
                if (! is_bool($value)) {
                    throw new NonRetryableJobException('export_contract_boolean');
                }

                return $value;
        }
        throw new NonRetryableJobException('export_contract_type');
    }
}
