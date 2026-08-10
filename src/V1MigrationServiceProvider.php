<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration;

use Illuminate\Support\ServiceProvider;

/**
 * Entry point for the ProjectSend v1 → v2 migration tool.
 *
 * This package is installed on purpose and removed afterwards. Migrating
 * happens once, if ever, and the engine it needs — arbitrary database
 * connections plus direct writes across the entire host schema — is not
 * something every install should carry idle. See the host repo's
 * docs/extension-points-architecture.md for the placement rule this
 * follows.
 *
 * Host integration contract (see README.md):
 *   - the `staff` route middleware alias must be registered, and an
 *     `edit_settings` Gate must be defined (the host's
 *     IdentityServiceProvider does this generically for every Permission
 *     case)
 *   - a queue worker must be running: a real import outlives any web
 *     request, so the UI queues a job and polls a row
 *   - `php artisan migrate` after installing, to create this package's
 *     two tables, and `npm run build` so its Inertia page enters the
 *     frontend bundle
 *
 * What this package must never do, because all three are closed PHP
 * enums the host casts database columns to: add an Action case, add a
 * Capability case, add a Setting case. A value invented here throws on
 * read for every row afterwards, including rows this tool never touched.
 */
class V1MigrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/v1-migration.php', 'v1-migration');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/v1-migration.php' => config_path('v1-migration.php'),
        ], 'v1-migration-config');

        // Inertia's server-side testing helper only looks under the host
        // app's own resources/js/pages, so assertInertia(...->component())
        // would not find a page shipped by a package. The frontend build
        // itself already merges both locations (the host's app.tsx globs
        // packages/* and vendor/*/*); this is the test-time equivalent.
        $testingPagePaths = config('inertia.testing.page_paths', []);
        $testingPagePaths[] = __DIR__.'/../resources/js/pages';
        config(['inertia.testing.page_paths' => $testingPagePaths]);
    }
}
