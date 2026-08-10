<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to import a v1 install.
 *
 * This is also the tool's audit trail. The host's Action enum is closed
 * and casts the activity_log.action column, so a package cannot record
 * "a migration happened" there without inventing a value that would
 * throw on every subsequent read of the log — the run row is the record
 * instead, and it holds more than a log line could anyway.
 *
 * Shaped after the host's zip_downloads table for the same reason it
 * was: a queued job writes status and error back onto a row, and the UI
 * polls that row. Same pattern, same expectations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v1_migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('pending');
            $table->string('mode');

            // Connection details or bundle location. Passwords are
            // stripped before this is written — the run record is shown
            // in a web UI and downloaded as a report.
            $table->json('source');

            // Which phase the job is on and how far through it is. Both
            // are the polling UI's only inputs; `total` is null until the
            // phase has counted its source rows.
            $table->string('phase')->nullable();
            $table->unsignedBigInteger('processed')->default(0);
            $table->unsignedBigInteger('total')->nullable();

            $table->json('options');

            // Preflight findings, per-phase counts, and every row that
            // was skipped and why. Grows through the run.
            $table->json('report')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v1_migration_runs');
    }
};
