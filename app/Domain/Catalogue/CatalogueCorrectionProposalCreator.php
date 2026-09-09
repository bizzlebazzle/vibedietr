<?php

namespace App\Domain\Catalogue;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\CatalogueCorrectionChange;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class CatalogueCorrectionProposalCreator
{
    public const REASON_MAX_LENGTH = 500;

    public function __construct(private CatalogueCorrectionFields $fields, private AuditEventRecorder $audit) {}

    /** @param array<string, mixed> $changes */
    public function create(User $proposer, int $itemId, string $baseVersionId, string $reason, array $changes): CatalogueCorrectionProposal
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:'.self::REASON_MAX_LENGTH]])->validate();

        return DB::transaction(function () use ($proposer, $itemId, $baseVersionId, $reason, $changes): CatalogueCorrectionProposal {
            $item = CatalogueItem::query()->lockForUpdate()->findOrFail($itemId);
            if ($item->status !== CatalogueItemStatus::Approved || $item->canonical_catalogue_item_id !== null || $item->current_catalogue_item_version_id === null) {
                throw ValidationException::withMessages(['catalogue_item' => 'Only an active approved catalogue item may receive corrections.']);
            }
            $base = CatalogueItemVersion::query()->whereKey($baseVersionId)->where('catalogue_item_id', $item->id)->first();
            if ($base === null) {
                throw ValidationException::withMessages(['base_version_id' => 'The base version must belong to the corrected catalogue item.']);
            }
            if ($changes === []) {
                throw ValidationException::withMessages(['changes' => 'Propose at least one factual change.']);
            }

            $prepared = [];
            foreach ($changes as $field => $raw) {
                try {
                    $before = $this->fields->current($base, $field);
                    $after = $this->fields->proposed($field, $raw);
                } catch (InvalidArgumentException|\ValueError $exception) {
                    throw ValidationException::withMessages(["changes.$field" => $exception->getMessage()]);
                }
                if (! $this->fields->equal($field, $before, $after)) {
                    $prepared[$field] = [$before, $after];
                }
            }
            if ($prepared === []) {
                throw ValidationException::withMessages(['changes' => 'A correction must materially differ from the base version.']);
            }

            $proposal = CatalogueCorrectionProposal::query()->forceCreate([
                'proposal_type' => CatalogueChangeProposalType::UserCorrection,
                'catalogue_item_id' => $item->id,
                'base_catalogue_item_version_id' => $base->id,
                'proposer_user_id' => $proposer->id,
                'reason' => $reason,
                'state' => CatalogueCorrectionProposalState::Pending,
                'submitted_at' => Date::now()->utc(),
            ]);
            foreach ($prepared as $field => [$before, $after]) {
                CatalogueCorrectionChange::query()->forceCreate([
                    'proposal_id' => $proposal->id,
                    'field_key' => $field,
                    'before_value' => $before,
                    'proposed_value' => $after,
                ]);
            }

            $this->audit->record(
                AuditAction::CatalogueCorrectionProposed,
                AuditActor::authenticatedUser($proposer),
                AuditSubject::resource(AuditSubjectType::CatalogueProposal, $proposal->id),
                ['catalogue_item_id' => $item->id, 'base_version_id' => $base->id, 'outcome' => 'pending'],
                evidenceReference: $proposal->id,
            );

            return $proposal->fresh(['changes']);
        }, 3);
    }
}
