<?php

namespace App\Domain\Catalogue;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\User;
use App\Observability\OperationalTelemetry;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ManualCatalogueSubmissionCreator
{
    public const DISTINCTION_MAX_LENGTH = 500;

    public function __construct(
        private ManualCatalogueDuplicateDetector $duplicates,
        private CatalogueNutritionNormalizer $nutrition,
        private AuditEventRecorder $audit,
        private OperationalTelemetry $telemetry,
    ) {}

    public function submit(User $submitter, ManualCatalogueSubmissionData $data): ManualCatalogueSubmissionResult
    {
        $strong = $this->duplicates->strongMatches($data);
        $fuzzy = $this->duplicates->fuzzySuggestions($data);

        if ($strong !== []) {
            if ($data->choice === null) {
                return new ManualCatalogueSubmissionResult(
                    ManualCatalogueSubmissionStatus::ChoiceRequired,
                    null,
                    $strong,
                    $fuzzy,
                );
            }

            $selected = collect($strong)->first(
                fn (ManualCatalogueDuplicateMatch $match): bool => $match->itemId === $data->duplicateItemId,
            );

            if (! $selected instanceof ManualCatalogueDuplicateMatch) {
                throw ValidationException::withMessages([
                    'duplicate_item_id' => 'Choose one of the current approved duplicate candidates.',
                ]);
            }

            if ($data->choice === ManualCatalogueSubmissionChoice::Reuse) {
                $item = CatalogueItem::query()
                    ->whereKey($selected->itemId)
                    ->where('status', CatalogueItemStatus::Approved)
                    ->firstOrFail();
                $this->telemetry->counter('catalogue.manual_submission', ['outcome' => 'reused']);

                return new ManualCatalogueSubmissionResult(
                    ManualCatalogueSubmissionStatus::Reused,
                    $item,
                    $strong,
                    $fuzzy,
                );
            }

            $explanation = CatalogueName::optional(
                $data->distinctionExplanation,
                self::DISTINCTION_MAX_LENGTH,
            );

            if ($explanation === null) {
                throw ValidationException::withMessages([
                    'distinction_explanation' => 'Explain briefly why this should be submitted as a distinct food.',
                ]);
            }
        } elseif ($data->choice !== null || $data->duplicateItemId !== null) {
            throw ValidationException::withMessages([
                'duplicate_choice' => 'The duplicate choice is stale. Submit the food details again.',
            ]);
        }

        $item = DB::transaction(function () use ($submitter, $data, $strong): CatalogueItem {
            $name = CatalogueName::display($data->name);
            $structure = $data->package;
            $hasPackage = $structure->packageCount !== null
                || $structure->itemType !== null
                || $structure->amountPerItem !== null
                || $structure->servingsPerItem !== null;
            $hasServing = $structure->servingAmount !== null;
            $now = Date::now()->toImmutable()->utc();
            $item = new CatalogueItem;
            $item->forceFill([
                'origin' => CatalogueItemOrigin::Manual,
                'barcode' => null,
                'submitted_by_user_id' => $submitter->getKey(),
                'source' => CatalogueItemSource::Manual,
                'source_identifier' => null,
                'introduced_at' => $now,
                'status' => CatalogueItemStatus::Pending,
                'current_catalogue_item_version_id' => null,
                'suggested_replacement_catalogue_item_id' => null,
            ]);
            $item->save();

            $version = new CatalogueItemVersion;
            $version->forceFill([
                'catalogue_item_id' => $item->getKey(),
                'version_number' => 1,
                'name' => $name,
                'normalized_name' => CatalogueName::normalize($name),
                'manual_food_classification' => $data->classification,
                'brand' => CatalogueName::optional($data->brand),
                'manufacturer' => CatalogueName::optional($data->manufacturer),
                'food_form' => CatalogueName::optional($data->foodForm),
                'preparation' => CatalogueName::optional($data->preparation),
                'treatment' => CatalogueName::optional($data->treatment),
                'composition' => CatalogueName::optional($data->composition, 1000),
                'name_source' => CatalogueItemSource::Manual,
                'package_source' => $hasPackage ? CatalogueItemSource::Manual : null,
                'serving_source' => $hasServing ? CatalogueItemSource::Manual : null,
                ...$structure->toAttributes(),
            ]);
            $version->save();
            $this->nutrition->store($version, $data->nutrition);
            $item->setCurrentVersion($version);

            $candidateCreated = false;
            if ($strong !== []) {
                $selected = collect($strong)->firstWhere('itemId', $data->duplicateItemId);
                assert($selected instanceof ManualCatalogueDuplicateMatch);
                app(CatalogueCandidateRecorder::class)->record(
                    (int) $item->getKey(), $selected->itemId, $selected->evidence,
                    $submitter, $data->distinctionExplanation,
                );
                $candidateCreated = true;
            }

            $this->audit->record(
                AuditAction::ManualCatalogueSubmissionCreated,
                AuditActor::authenticatedUser($submitter),
                AuditSubject::resource(AuditSubjectType::CatalogueItem, (int) $item->getKey()),
                [
                    'candidate_created' => $candidateCreated,
                    'duplicate_path' => $strong === [] ? 'none' : 'continue_distinct',
                    'outcome' => 'pending',
                ],
            );

            return $item->fresh(['currentVersion.nutrientValues.sourceObservation']);
        }, 3);

        $this->telemetry->counter('catalogue.manual_submission', [
            'outcome' => $strong === [] ? 'created' : 'created_distinct',
        ]);

        return new ManualCatalogueSubmissionResult(
            ManualCatalogueSubmissionStatus::Created,
            $item,
            $strong,
            $fuzzy,
        );
    }
}
