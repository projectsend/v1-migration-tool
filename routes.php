<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use ProjectSend\V1Migration\Http\Controllers\MigrationController;

/*
 * Registered by V1MigrationServiceProvider inside the host's `web`,
 * `auth` and `staff` middleware, and gated on `edit_settings`.
 *
 * There is no sidebar entry for any of this. A one-time tool does not
 * earn a permanent slot in the navigation of an install that will use it
 * once and then remove it — the URL is the documented entry point.
 */

Route::prefix('system/migrate')->name('v1-migration.')->group(function (): void {
    Route::get('/', [MigrationController::class, 'index'])->name('index');
    Route::get('exporter', [MigrationController::class, 'exporter'])->name('exporter');
    Route::post('/', [MigrationController::class, 'store'])->name('store');

    // Polled by the page; plain JSON, not an Inertia visit.
    Route::get('runs/{run}', [MigrationController::class, 'show'])->name('show');
    Route::post('runs/{run}/accept', [MigrationController::class, 'accept'])->name('accept');
});
