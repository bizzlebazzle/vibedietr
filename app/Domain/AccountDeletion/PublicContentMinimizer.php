<?php

namespace App\Domain\AccountDeletion;

use App\Domain\AccountExports\ExportContract;
use RuntimeException;

/** Known source fields are validated before an explicit public projection. */
final class PublicContentMinimizer
{
    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function recipe(array $snapshot): array
    {
        if (($snapshot['visibility'] ?? null) !== 'public' || ! is_string($snapshot['title'] ?? null)) {
            throw new RuntimeException('retained_snapshot_not_public');
        }
        $projection = app(ExportContract::class)->project('content', $snapshot);

        return $this->minimize($this->approved($snapshot, $projection));
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function nutrition(array $snapshot): array
    {
        $projection = app(ExportContract::class)->project('nutrition', $snapshot);

        return $this->minimize($this->approved($snapshot, $projection));
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function catalogue(array $snapshot): array
    {
        if (! is_string($snapshot['name'] ?? null)) {
            throw new RuntimeException('retained_catalogue_not_public');
        }
        $projection = app(ExportContract::class)->project('catalogue_content', $snapshot);

        return $this->minimize($this->approved($snapshot, $projection));
    }

    /** Preserve stored numeric tokens while excluding every schema-omitted field.
     * @param  array<mixed>  $source
     * @return array<mixed>
     */
    private function approved(array $source, mixed $projection): array
    {
        $allowed = (array) $projection;
        foreach ($source as $key => $value) {
            if (! array_key_exists($key, $allowed)) {
                unset($source[$key]);
            } elseif (is_array($value)) {
                $source[$key] = $this->approved($value, $allowed[$key]);
            }
        }

        return $source;
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    public function minimize(array $value): array
    {
        foreach ($value as $key => $child) {
            if (in_array($key, [
                'source_creator_user_id', 'creator_user_id', 'public_attribution_name',
                'recipe_import_id', 'correlation_id', 'submitted_url', 'final_url', 'override_event_id',
                'extracted_at', 'parsed_at', 'extractor', 'extractor_version', 'parser', 'parser_version',
                'preprocessing_version', 'quality_policy_version', 'redirect_count', 'extraction_method',
            ], true)) {
                unset($value[$key]);
            } elseif (is_array($child)) {
                $value[$key] = $this->minimize($child);
            }
        }

        return $value;
    }
}
