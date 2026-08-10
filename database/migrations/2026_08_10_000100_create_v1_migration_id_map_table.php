<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1 primary key -> v2 primary key, for every entity the import creates.
 *
 * The spine of the whole tool. Phases run in dependency order but never
 * share memory — the files phase resolves uploader ids through this
 * table, the assignments phase resolves both file and client ids through
 * it, and a job that was recycled mid-run picks up with nothing in
 * memory at all. It is also what makes a chunk safely re-appliable:
 * rows already mapped are skipped rather than duplicated.
 *
 * It deliberately outlives the run. Legacy download URLs (v1 mailed out
 * `download.php?id=417&token=…` for years) can only be redirected to the
 * right v2 file by looking the old id up here, so dropping this table
 * after a successful import would throw away the one thing that makes
 * those links recoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v1_migration_id_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('v1_migration_runs')->cascadeOnDelete();

            // 'user', 'file', 'group', 'folder', 'category', 'role'…
            $table->string('entity', 32);
            $table->unsignedBigInteger('source_id');

            // Null when the row was deliberately not imported — a file
            // whose bytes are gone, an encrypted file, a hidden
            // assignment. `status` and `note` say which.
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 16)->default('imported');
            $table->string('note')->nullable();

            $table->unique(['run_id', 'entity', 'source_id']);
            $table->index(['entity', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v1_migration_id_map');
    }
};
