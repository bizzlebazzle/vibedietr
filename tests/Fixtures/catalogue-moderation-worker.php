<?php

use App\Domain\Catalogue\CatalogueCandidateRecorder;
use App\Domain\Catalogue\CatalogueDuplicateEvidence;
use App\Domain\Catalogue\CatalogueModeration;
use App\Domain\Catalogue\CatalogueModerationAuthorization;
use App\Models\CatalogueDuplicateCandidate;
use App\Models\User;
use App\Security\SecondFactor\RecentAuthentication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Test-only process: refuse to bootstrap against any non-test database.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'testing') {
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== 'testing') {
    exit(2);
}
$mode = $argv[1];
if ($mode === 'pair') {
    echo "ready\n";
    $candidate = app(CatalogueCandidateRecorder::class)->record((int) $argv[2], (int) $argv[3], CatalogueDuplicateEvidence::ExactPrimaryName);
    echo $candidate->id, "\n";
    exit(0);
}
$actor = User::query()->findOrFail((int) $argv[2]);
$candidate = CatalogueDuplicateCandidate::query()->findOrFail((int) $argv[3]);
$session = app('session.store');
app(RecentAuthentication::class)->confirmPrimary($actor, 'correct-password', $session);
app(RecentAuthentication::class)->rememberFreshFactor($actor, CatalogueModerationAuthorization::OPERATION, $session);
$review = ['revision' => $candidate->moderation_revision, 'first_version_id' => $candidate->firstItem->current_catalogue_item_version_id,
    'second_version_id' => $candidate->secondItem->current_catalogue_item_version_id, 'reason_code' => 'duplicate', 'confirm_merge' => true];
DB::listen(function ($query): void {
    if (DB::transactionLevel() > 0 && str_contains($query->sql, 'exists') && str_contains($query->sql, 'is_administrator')) {
        echo "snapshot-established\n";
    }
});
try {
    if ($mode === 'confirm') {
        $review['identity_reviewed'] = true;
        app(CatalogueModeration::class)->confirmDuplicate($candidate->id, (int) $argv[4], $actor, $session, $review);
    } else {
        app(CatalogueModeration::class)->mergeApproved($candidate->id, (int) $argv[4], $actor, $session, $review);
    }
} catch (ValidationException) {
    echo "conflict\n";
    exit(3);
}
echo "merged\n";
