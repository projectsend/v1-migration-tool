<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use ProjectSend\V1Migration\Files\FileTransfer;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\Jobs\RunMigrationJob;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Source\SourceFactory;
use ProjectSend\V1Migration\Source\V1Config;
use Throwable;

/**
 * The screen at /system/migrate.
 *
 * It queues and watches; it never imports inside a request. Even
 * *checking* a v1 install reads every account and file row, which on the
 * installs this tool exists for is not something to do with a browser
 * waiting — so the page creates a run, the worker does the reading, and
 * the page polls the row. That is also why there is no progress
 * websocket: the run row already holds phase, processed and total, and
 * polling it is the same pattern the host uses for zip downloads.
 *
 * There is deliberately no bundle upload. A bundle that carries file
 * bytes is not a form post, and one that does not still needs its files
 * moved separately — so both cases end with the operator putting
 * something on the server, and asking for a path is honest about that.
 * The exporter that produces the bundle is downloadable from here, so at
 * least it is always the version that matches this importer.
 */
final class MigrationController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('system/migrate', [
            'run' => $this->present(MigrationRun::query()->latest('id')->first()),
            'directModeAvailable' => (bool) config('v1-migration.direct_mode'),
            'hostIsFresh' => $this->hostIsFresh(),
            'strategies' => FileTransfer::strategies(),
        ]);
    }

    /**
     * Hand out the exporter, so the customer's v1 box always gets the
     * version that matches this importer rather than whatever was
     * downloaded months ago.
     */
    public function exporter(): BinaryFileResponse
    {
        return response()->download(
            __DIR__.'/../../../bin/projectsend-v1-export.php',
            'projectsend-v1-export.php',
            ['Content-Type' => 'text/plain'],
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $directAvailable = (bool) config('v1-migration.direct_mode');

        $validated = $request->validate([
            'mode' => ['required', Rule::in($directAvailable ? ['direct', 'bundle'] : ['bundle'])],
            'bundle_path' => ['required_if:mode,bundle', 'nullable', 'string'],
            'install_path' => ['required_if:mode,direct', 'nullable', 'string'],
            'db_host' => ['nullable', 'string'],
            'db_port' => ['nullable', 'string'],
            'db_name' => ['nullable', 'string'],
            'db_user' => ['nullable', 'string'],
            'db_password' => ['nullable', 'string'],
            'prefix' => ['nullable', 'string'],
            'files' => ['required', Rule::in(FileTransfer::strategies())],
            'history' => ['required', Rule::in(['full', 'none'])],
        ]);

        if (! $this->hostIsFresh()) {
            return back()->withErrors([
                'mode' => 'This install already has content. The migration tool imports into a fresh ProjectSend only.',
            ]);
        }

        try {
            $source = $validated['mode'] === 'bundle'
                ? ['bundle_path' => rtrim((string) $validated['bundle_path'], '/')]
                : $this->directSource($validated);
        } catch (Throwable $e) {
            return back()->withErrors(['install_path' => $e->getMessage()]);
        }

        $run = MigrationRun::create([
            'status' => MigrationRun::STATUS_PENDING,
            'mode' => $validated['mode'],
            'source' => $source,
            'options' => [
                'files' => $validated['files'],
                'history' => $validated['history'],
                'checksums' => true,
            ],
        ]);

        RunMigrationJob::dispatch($run->id);

        return back()->with('success', 'Checking the ProjectSend v1 install…');
    }

    /**
     * Polled by the page every couple of seconds. Plain JSON rather than
     * an Inertia visit, matching how the host polls its zip downloads.
     */
    public function show(MigrationRun $run): JsonResponse
    {
        return response()->json($this->present($run));
    }

    /**
     * The operator confirming they have read what v2 cannot take.
     */
    public function accept(MigrationRun $run): RedirectResponse
    {
        if ($run->status !== MigrationRun::STATUS_NEEDS_ACKNOWLEDGEMENT) {
            return back();
        }

        $run->options = ['accept_skips' => true] + $run->options;
        $run->status = MigrationRun::STATUS_PENDING;
        $run->save();

        RunMigrationJob::dispatch($run->id);

        return back()->with('success', 'Importing…');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function directSource(array $validated): array
    {
        $installPath = rtrim((string) $validated['install_path'], '/');
        $config = V1Config::read($installPath);

        return [
            'install_path' => $installPath,
            'host' => $this->fallback($validated['db_host'] ?? null, $config->get('DB_HOST', '127.0.0.1')),
            'port' => $this->fallback($validated['db_port'] ?? null, $config->get('DB_PORT', '3306')),
            'database' => $this->fallback($validated['db_name'] ?? null, $config->get('DB_NAME')),
            'username' => $this->fallback($validated['db_user'] ?? null, $config->get('DB_USER')),

            // Encrypted, not discarded: the run spans several worker
            // invocations and each one reconnects. Redacted again on the
            // way out — see SourceFactory::redact().
            'password_encrypted' => Crypt::encryptString(
                $this->fallback($validated['db_password'] ?? null, $config->get('DB_PASSWORD')),
            ),
            'prefix' => $this->fallback($validated['prefix'] ?? null, $config->get('TABLES_PREFIX', 'tbl_')),
            'has_encryption_key' => $config->hasEncryptionKey(),
            'version' => $config->version($installPath),
        ];
    }

    private function fallback(mixed $given, string $default): string
    {
        return is_string($given) && $given !== '' ? $given : $default;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function present(?MigrationRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'mode' => $run->mode,
            'source' => SourceFactory::redact($run->source),
            'phase' => $run->phase,
            'processed' => $run->processed,
            'total' => $run->total,
            'options' => $run->options,
            'report' => $run->report,
            'error' => $run->error,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * One staff account and nothing else — an install that has been set
     * up and not yet used.
     */
    private function hostIsFresh(): bool
    {
        if (DB::table(HostTables::USERS)->count() > 1) {
            return false;
        }

        foreach (HostTables::mustBeEmpty() as $table) {
            if (DB::table($table)->exists()) {
                return false;
            }
        }

        return true;
    }
}
