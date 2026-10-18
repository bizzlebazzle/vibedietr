<?php

namespace App\Http\Controllers;

use App\Domain\AccountExports\ExportAudit;
use App\Domain\AccountExports\ExportStorage;
use App\Domain\AccountExports\RequestAccountExport;
use App\Models\AccountExport;
use App\Observability\CorrelationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AccountExportController extends Controller
{
    public function index(Request $request)
    {
        return response()->view('account-exports.index', [
            'exports' => AccountExport::query()->where('user_id', $request->user()->getKey())->latest()->limit(20)->get(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, RequestAccountExport $service, CorrelationContext $correlation)
    {
        $service->request($request->user(), $correlation->get());

        return to_route('account-exports.index')->with('status', 'Export requested. Refresh this page to check progress.');
    }

    public function download(Request $request, string $export)
    {
        $stream = DB::transaction(function () use ($request, $export) {
            $record = AccountExport::query()->where('user_id', $request->user()->getKey())->lockForUpdate()->findOrFail($export);
            abort_unless($record->state === 'ready' && $record->expires_at->isFuture(), 410);
            $stream = app(ExportStorage::class)->disk($record->disk)->readStream($record->storageKey());
            abort_unless(is_resource($stream), 410);
            try {
                app(ExportAudit::class)->record($record, 'downloaded', $request->user());
            } catch (\Throwable $exception) {
                fclose($stream);
                throw $exception;
            }

            return $stream;
        });

        return response()->streamDownload(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'vibedietr-account-export-v1-'.$export.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private']);
    }
}
