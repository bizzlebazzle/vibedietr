<?php

namespace App\Models;

use App\Domain\Catalogue\CatalogueDuplicateCandidateStatus;
use App\Domain\Catalogue\CatalogueDuplicateEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property CatalogueDuplicateCandidateStatus $status
 * @property CatalogueDuplicateEvidence $evidence
 */
class CatalogueDuplicateCandidate extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['distinction_explanation', 'submitted_by_user_id'];

    protected static function booted(): void
    {
        static::saving(function (CatalogueDuplicateCandidate $candidate): void {
            if ($candidate->first_catalogue_item_id >= $candidate->second_catalogue_item_id) {
                throw new LogicException('Duplicate candidate pairs must be stored in canonical order.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => CatalogueDuplicateCandidateStatus::class,
            'evidence' => CatalogueDuplicateEvidence::class,
        ];
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function firstItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class, 'first_catalogue_item_id');
    }

    /** @return BelongsTo<CatalogueItem, $this> */
    public function secondItem(): BelongsTo
    {
        return $this->belongsTo(CatalogueItem::class, 'second_catalogue_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
