<?php

namespace App\Models;

use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\RecipeImportType;
use Carbon\CarbonImmutable;
use Database\Factories\RecipeImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property array<string, mixed>|null $provenance
 * @property list<string>|null $warnings
 * @property RecipeImportType $type
 * @property RecipeImportStatus $status
 * @property CarbonImmutable|null $processing_lease_until
 */
class RecipeImport extends Model
{
    /** @use HasFactory<RecipeImportFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'user_id', 'recipe_id'];

    protected static function booted(): void
    {
        static::creating(function (RecipeImport $import): void {
            $import->id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => RecipeImportType::class,
            'status' => RecipeImportStatus::class,
            'requires_review' => 'boolean',
            'warnings' => 'array',
            'provenance' => 'array',
            'manual_retry_count' => 'integer',
            'extracted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'processing_lease_until' => 'immutable_datetime',
            'source_stored_at' => 'immutable_datetime',
            'cleanup_completed_at' => 'immutable_datetime',
            'source_bytes' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
