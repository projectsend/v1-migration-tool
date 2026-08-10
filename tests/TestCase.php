<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Tests;

use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;
use ProjectSend\V1Migration\Tests\Support\HostSchema;
use ProjectSend\V1Migration\V1MigrationServiceProvider;

/**
 * The package is tested against a throwaway Testbench app, never the
 * real host — same arrangement as community-modules. Host-provided
 * concerns are stood in for here: the `staff` middleware alias, the
 * `edit_settings` Gate the host's IdentityServiceProvider defines
 * generically for every Permission case, and the host tables themselves
 * (Support\HostSchema).
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [V1MigrationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');

        $app->make('router')->aliasMiddleware('staff', Support\TestStaffMiddleware::class);

        Gate::define('edit_settings', static fn (): bool => true);
    }

    protected function defineDatabaseMigrations(): void
    {
        HostSchema::create();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
