<?php

// Synthetic-only reference drill. Never available as an application HTTP route.
use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\ErasureEvidenceSchema;
use App\Domain\AccountDeletion\ErasureJournalSchema;
use App\Domain\AccountDeletion\ErasureRestoreReplay;
use App\Domain\AccountDeletion\JournalManifest;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Domain\AccountDeletion\RestoreOperationalState;
use App\Domain\AccountDeletion\RestoreRelease;
use App\Domain\AccountDeletion\RestoreReleaseCertificate;
use App\Domain\Diary\DiaryConsumptionManager;
use App\Domain\MealPlans\MealPlanBookmarkManager;
use App\Domain\MealPlans\MealPlanCopier;
use App\Domain\Recipes\RecipeRemixCreator;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\MealPlanDay;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\PrivateConsumptionArchive;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\User;
use App\Operations\DatabaseReconciliation;
use App\Operations\PrivateObjectRecovery;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, 'Synthetic privacy fixture failed at '.basename($exception->getFile()).':'.$exception->getLine().' ('.$exception::class.").\n");
    exit(1);
});
if (! $app->environment('testing') || getenv('RECOVERY_DRILL') !== 'yes'
    || ! str_starts_with((string) config('database.connections.mysql.host'), 'dep06-')) {
    throw new RuntimeException('Synthetic isolated drill environment required');
}
$mode = $argv[1] ?? '';
if ($mode === 'target-id') {
    echo (string) Str::ulid();
    exit;
}
$path = '/drill/privacy-fixtures.json';
$assert = function (bool $condition): void {
    if (! $condition) {
        throw new RuntimeException('synthetic_privacy_verification_failed');
    }
};
$negative = function (callable $action) use ($assert): void {
    try {
        $action();
    } catch (Throwable) {
        return;
    }
    $assert(false);
};
$client = function (): S3Client {
    $disk = config('filesystems.disks.s3');

    return new S3Client(['version' => 'latest', 'region' => $disk['region'], 'endpoint' => $disk['endpoint'],
        'use_path_style_endpoint' => true, 'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
        'request_checksum_calculation' => 'when_required']);
};
if ($mode === 'mirror-repair') {
    $negative(fn () => app(ProtectedErasureJournal::class)->checkpoint());
    app(ErasureJournalSchema::class)->install(DB::connection('erasure_mirror'));
    app(ProtectedErasureJournal::class)->repair();
    app(ProtectedErasureJournal::class)->checkpoint();
} elseif ($mode === 'seed') {
    foreach (config('erasure.journal_connections') as $name) {
        app(ErasureJournalSchema::class)->install(DB::connection($name));
    }
    DB::connection('erasure_primary')->statement('CREATE DATABASE IF NOT EXISTS evidence');
    app(ErasureEvidenceSchema::class)->install(DB::connection('erasure_evidence'));
    $pair = sodium_crypto_sign_keypair();
    file_put_contents('/drill/signing.env', 'ERASURE_RELEASE_SIGNING_KEY=base64:'.base64_encode(sodium_crypto_sign_secretkey($pair))."\n"
        .'ERASURE_RELEASE_PUBLIC_KEY=base64:'.base64_encode(sodium_crypto_sign_publickey($pair))."\n");
    chmod('/drill/signing.env', 0600);
    $clock = CarbonImmutable::now('UTC')->startOfSecond();
    $other = User::query()->where('email', 'other@drill.example.test')->sole();
    $ownership = json_decode(file_get_contents('/drill/ownership.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['owner', 'other'] as $name) {
        $ownership['durable/'.$name]['owner_generation'] = User::query()->where('email', $name.'@drill.example.test')->value('account_generation');
    }
    RecipeImport::factory()->withDraft()->create([
        'user_id' => $other->id, 'source_text' => 'SYNTHETIC_DURABLE_IMPORT_REVIEW_TEXT',
    ]);
    $partial = [];
    $cases = [];
    foreach (['expired', 'waived', 'under13', 'cancelled', 'removed', 'pending', 'interrupted', 'boundary'] as $name) {
        Date::setTestNow($name === 'expired' ? $clock->subDays(29) : ($name === 'boundary' ? $clock->subDays(28)->addSecond() : $clock));
        $owner = User::factory()->create(['email' => $name.'@privacy.example.test', 'timezone' => 'UTC']);
        $private = Recipe::factory()->validDraft()->for($owner, 'owner')->create();
        $public = Recipe::factory()->finalizedPublic()->for($owner, 'owner')->create();
        $plan = MealPlan::factory()->public()->for($owner, 'owner')->create(['name' => 'Synthetic '.$name.' plan']);
        $history = null;
        if ($name === 'removed') {
            $plan->forceFill(['visibility' => 'private', 'type' => 'dated', 'starts_on' => today(), 'ends_on' => today()])->save();
            $day = MealPlanDay::factory()->for($plan)->create(['date' => today(), 'day_index' => null]);
            $slot = MealPlanSlot::factory()->for($day, 'day')->create();
            $entry = MealPlanRecipeEntry::factory()->for($slot, 'slot')->create(['recipe_id' => $public->id,
                'recipe_version_id' => $public->current_recipe_version_id, 'recipe_snapshot' => $public->currentVersion->snapshot]);
            $history = app(DiaryConsumptionManager::class)->consumeRecipe($entry, '0.75', null, null, null, $owner)->id;
            $plan->forceFill(['visibility' => 'public'])->save();
        }
        $bookmark = MealPlanBookmark::factory()->for($plan)->for($other, 'owner')->create();
        $copy = app(MealPlanCopier::class)->copy($plan->id, $other);
        $remix = app(RecipeRemixCreator::class)->create($public->id, $public->current_recipe_version_id, (string) Str::ulid(), $other);
        if ($name === 'waived') {
            $partial = [
                'user' => (array) DB::table('users')->where('id', $owner->id)->first(),
                'recipe' => (array) DB::table('recipes')->where('id', $private->id)->first(),
            ];
        }
        $request = in_array($name, ['expired', 'cancelled', 'removed', 'boundary'], true)
            ? app(AccountLifecycle::class)->request($owner) : null;
        $cases[$name] = ['owner' => $owner->id, 'generation' => $owner->account_generation, 'private' => $private->id,
            'public' => $public->id, 'plan' => $plan->id, 'bookmark' => $bookmark->id, 'copy' => $copy->id,
            'remix' => $remix->id, 'request' => $request?->id, 'history' => $history];
        $key = 'durable/privacy-'.$name;
        Storage::disk('s3')->put($key, 'Synthetic private '.$name.' bytes');
        $ownership[$key] = ['owner_generation' => $owner->account_generation, 'resource' => 'synthetic-private-object'];
    }
    Date::setTestNow();
    file_put_contents($path, json_encode(['clock' => $clock->format('Y-m-d\\TH:i:s\\Z'), 'cases' => $cases, 'partial' => $partial], JSON_THROW_ON_ERROR));
    file_put_contents('/drill/ownership.json', json_encode($ownership, JSON_THROW_ON_ERROR));
} else {
    $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    Date::setTestNow(CarbonImmutable::parse($fixture['clock'])->addDays(2));
    $cases = $fixture['cases'];
    if ($mode === 'database-only') {
        $negative(fn () => app(RestoreReleaseCertificate::class)->assertReleased());
        app(ErasureRestoreReplay::class)->replay(app(ProtectedErasureJournal::class)->checkpoint());
        app(RestoreOperationalState::class)->invalidate();
        $mode = 'verify';
    } elseif ($mode === 'object-only') {
        $negative(fn () => app(RestoreReleaseCertificate::class)->assertReleased());
        $assert(! User::query()->whereKey($cases['waived']['owner'])->exists());
        $before = app(DatabaseReconciliation::class)->capture(DB::connection());
        $head = app(ProtectedErasureJournal::class)->checkpoint();
        app(PrivateObjectRecovery::class)->restoreFiltered($client(), config('filesystems.disks.s3.bucket'),
            '/drill/privacy-restored/work/privacy-set/objects', $head);
        app(PrivateObjectRecovery::class)->verifyFiltered($client(), config('filesystems.disks.s3.bucket'),
            '/drill/privacy-restored/work/privacy-set/objects', $head);
        $assert(json_encode($before, JSON_THROW_ON_ERROR) === json_encode(app(DatabaseReconciliation::class)->capture(DB::connection()), JSON_THROW_ON_ERROR));
        $mode = 'verify';
    }
    if ($mode === 'advance') {
        foreach (['waived', 'under13', 'interrupted'] as $name) {
            $owner = User::query()->findOrFail($cases[$name]['owner']);
            $request = app(AccountLifecycle::class)->request($owner, waive: $name !== 'under13', under13: $name === 'under13');
            if ($name === 'interrupted') {
                $intent = app(AccountLifecycle::class)->intent($request, 'purge_started');
                app(JournalManifest::class)->append((string) Str::ulid(), $intent);
                app(PurgeAccount::class)->erase($intent, $intent['resources']);
            } else {
                app(PurgeAccount::class)->purge($request->id);
            }
        }
        app(AccountLifecycle::class)->recover(User::query()->findOrFail($cases['cancelled']['owner']));
        app(MealPlanBookmarkManager::class)->remove(MealPlanBookmark::query()->findOrFail($cases['removed']['bookmark']),
            User::query()->where('email', 'other@drill.example.test')->sole());
        app(AccountLifecycle::class)->recover(User::query()->findOrFail($cases['removed']['owner']));
        app(AccountLifecycle::class)->request(User::query()->findOrFail($cases['pending']['owner']));
    } elseif ($mode === 'prepare') {
        $journal = app(ProtectedErasureJournal::class);
        $proof = app(RestoreRelease::class)->prepare('/drill/privacy-restored/work/privacy-set', $journal->checkpoint(), $client());
        file_put_contents('/drill/privacy-prepared.json', json_encode($proof, JSON_THROW_ON_ERROR));
        chmod('/drill/privacy-prepared.json', 0600);
    } elseif (in_array($mode, ['verify', 'release', 'write-negative', 'clock-negative'], true)) {
        foreach (['expired', 'waived', 'under13', 'interrupted'] as $name) {
            $case = $cases[$name];
            $assert(! User::query()->whereKey($case['owner'])->exists());
            $assert(! Recipe::query()->whereKey($case['private'])->exists());
            if ($name === 'under13') {
                $assert(! Recipe::query()->whereKey($case['public'])->exists());
                $assert(! MealPlan::query()->whereKey($case['plan'])->exists());
            } else {
                $assert(Recipe::query()->whereKey($case['public'])->whereNull('user_id')->exists());
                $assert(MealPlan::query()->whereKey($case['plan'])->whereNull('user_id')->where('visibility', 'retained_unlisted')->exists());
            }
            $assert(! Storage::disk('s3')->exists('durable/privacy-'.$name));
        }
        foreach ($cases as $case) {
            $assert(MealPlan::query()->whereKey($case['copy'])->exists());
            $assert(Recipe::query()->whereKey($case['remix'])->exists());
        }
        $assert(User::query()->findOrFail($cases['cancelled']['owner'])->isActive());
        $assert(MealPlan::query()->whereKey($cases['cancelled']['plan'])->value('user_id') === $cases['cancelled']['owner']);
        $assert(User::query()->findOrFail($cases['removed']['owner'])->isActive());
        $assert(! MealPlan::query()->whereKey($cases['removed']['plan'])->exists());
        $archive = PrivateConsumptionArchive::query()->where('user_id', $cases['removed']['owner'])->sole();
        $assert($archive->history['transitions'][0]['id'] === $cases['removed']['history']);
        $assert(! User::query()->findOrFail($cases['pending']['owner'])->isActive());
        $assert(Storage::disk('s3')->exists('durable/privacy-cancelled'));
        $assert(DB::table('sessions')->count() === 0 && DB::table('jobs')->count() === 0);
        $assert(RecipeImport::query()->where('source_text', 'SYNTHETIC_DURABLE_IMPORT_REVIEW_TEXT')->exists());
        $prepared = json_decode(file_get_contents('/drill/privacy-prepared.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($mode === 'write-negative') {
            $id = $cases['cancelled']['owner'];
            $original = DB::table('users')->where('id', $id)->value('name');
            DB::table('users')->where('id', $id)->update(['name' => 'Synthetic changed after verification']);
            DB::table('users')->where('id', $id)->update(['name' => $original]);
            $negative(fn () => app(RestoreRelease::class)->release('/drill/privacy-restored/work/privacy-set', $prepared, $client()));
        } elseif ($mode === 'clock-negative') {
            Date::setTestNow(CarbonImmutable::parse($fixture['clock'])->addDays(2)->addSecond());
            $negative(fn () => app(RestoreRelease::class)->release('/drill/privacy-restored/work/privacy-set', $prepared, $client()));
        } elseif ($mode === 'release') {
            $certificate = app(RestoreRelease::class)->release('/drill/privacy-restored/work/privacy-set', $prepared, $client());
            file_put_contents('/drill/privacy-release.json', json_encode($certificate, JSON_THROW_ON_ERROR));
            chmod('/drill/privacy-release.json', 0600);
            config(['erasure.release_signing_key' => null]);
            app(RestoreReleaseCertificate::class)->assertReleased();
        }
    } elseif ($mode === 'unreleased') {
        $negative(fn () => app(RestoreReleaseCertificate::class)->assertReleased());
    } elseif ($mode === 'journal-negative') {
        $journal = app(ProtectedErasureJournal::class);
        $head = $journal->checkpoint();
        config(['erasure.encryption_key' => 'base64:'.base64_encode(random_bytes(32))]);
        $negative(fn () => $journal->read($head));
    } elseif ($mode === 'repeat') {
        $journal = app(ProtectedErasureJournal::class);
        app(ErasureRestoreReplay::class)->replay($journal->checkpoint());
        app(ErasureRestoreReplay::class)->replay($journal->checkpoint());
    } elseif ($mode === 'selective') {
        $negative(fn () => app(RestoreReleaseCertificate::class)->assertReleased());
        $assert(! User::query()->whereKey($cases['waived']['owner'])->exists());
        $receipts = DB::table('account_erasure_receipts')->count();
        DB::transaction(function () use ($fixture): void {
            DB::table('users')->insert($fixture['partial']['user']);
            DB::table('recipes')->insert($fixture['partial']['recipe']);
        });
        $assert(Recipe::query()->whereKey($cases['waived']['private'])->exists());
        app(ErasureRestoreReplay::class)->replay(app(ProtectedErasureJournal::class)->checkpoint());
        $assert(! User::query()->whereKey($cases['waived']['owner'])->exists());
        $assert(! Recipe::query()->whereKey($cases['waived']['private'])->exists());
        $assert(Recipe::query()->whereKey($cases['waived']['remix'])->exists());
        $assert(MealPlan::query()->whereKey($cases['waived']['copy'])->exists());
        $assert($receipts === DB::table('account_erasure_receipts')->count());
    } else {
        throw new RuntimeException('synthetic_privacy_mode_invalid');
    }
}
echo "Synthetic privacy operation passed.\n";
