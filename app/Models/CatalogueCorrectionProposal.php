<?php

namespace App\Models;

use App\Domain\Catalogue\CatalogueChangeProposalType;
use App\Domain\Catalogue\CatalogueCorrectionProposalState;
use Database\Factories\CatalogueCorrectionProposalFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property string $id
 * @property CatalogueChangeProposalType $proposal_type
 * @property int $catalogue_item_id
 * @property string $base_catalogue_item_version_id
 * @property int|null $proposer_user_id
 * @property string|null $provider_refresh_id
 * @property string $reason
 * @property CatalogueCorrectionProposalState $state
 * @property-read CatalogueItem $catalogueItem
 * @property-read CatalogueItemVersion $baseVersion
 * @property-read User|null $proposer
 * @property-read Collection<int, CatalogueCorrectionChange> $changes
 */
class CatalogueCorrectionProposal extends Model
{
    /** @use HasFactory<CatalogueCorrectionProposalFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected $hidden = ['reason', 'proposer_user_id'];

    protected static function booted(): void
    {
        static::updating(function (self $proposal): void {
            if (array_diff(array_keys($proposal->getDirty()), ['state', 'decided_at', 'updated_at']) !== []) {
                throw new LogicException('Submitted correction evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Correction proposals are retained moderation evidence.'));
    }

    protected function casts(): array
    {
        return [
            'proposal_type' => CatalogueChangeProposalType::class,
            'state' => CatalogueCorrectionProposalState::class,
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function catalogueItem(): BelongsTo
    /** @return BelongsTo<CatalogueItem, $this> */
    {
        return $this->belongsTo(CatalogueItem::class);
    }

    public function baseVersion(): BelongsTo
    /** @return BelongsTo<CatalogueItemVersion, $this> */
    {
        return $this->belongsTo(CatalogueItemVersion::class, 'base_catalogue_item_version_id');
    }

    public function proposer(): BelongsTo
    /** @return BelongsTo<User, $this> */
    {
        return $this->belongsTo(User::class, 'proposer_user_id');
    }

    /** @return BelongsTo<CatalogueProviderRefresh, $this> */
    public function providerRefresh(): BelongsTo
    {
        return $this->belongsTo(CatalogueProviderRefresh::class, 'provider_refresh_id');
    }

    public function changes(): HasMany
    /** @return HasMany<CatalogueCorrectionChange, $this> */
    {
        return $this->hasMany(CatalogueCorrectionChange::class, 'proposal_id');
    }

    public function decision(): HasOne
    /** @return HasOne<CatalogueModerationDecision, $this> */
    {
        return $this->hasOne(CatalogueModerationDecision::class, 'correction_proposal_id');
    }
}
