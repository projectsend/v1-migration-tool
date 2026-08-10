<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\Jobs\RunMigrationJob;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Tests\Support\BundleBuilder;
use ProjectSend\V1Migration\Tests\Support\FakeUser;

function setupAdmin(): FakeUser
{
    $id = DB::table(HostTables::USERS)->insertGetId([
        'type' => 'staff',
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return FakeUser::query()->findOrFail($id);
}

function tinyBundle(): string
{
    return (new BundleBuilder)
        ->table(V1Tables::OPTIONS, [
            ['id' => 1, 'name' => 'this_install_title', 'value' => 'Northwind'],
        ])
        ->table(V1Tables::ROLES, [
            ['id' => 1, 'name' => 'Client', 'is_system_role' => 1, 'created_date' => '2026-01-01 00:00:00'],
        ])
        ->table(V1Tables::USERS, [
            [
                'id' => 1, 'user' => 'acme', 'name' => 'Acme &amp; Co.',
                'email' => 'acme@example.com', 'password' => '$2y$08$abcdefghijklmnopqrstuv',
                'role_id' => 1, 'active' => 1, 'max_disk_quota' => 500,
                'timestamp' => '2026-01-02 00:00:00',
            ],
        ])
        ->write();
}

it('shows the screen to staff who may edit settings', function (): void {
    $this->actingAs(setupAdmin())
        ->get('/system/migrate')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('system/migrate')
            ->where('hostIsFresh', true)
            ->where('run', null)
            ->has('strategies'));
});

it('queues a run and reports it as fresh', function (): void {
    $admin = setupAdmin();
    Queue::fake();

    $this->actingAs($admin)
        ->post('/system/migrate', [
            'mode' => 'bundle',
            'bundle_path' => tinyBundle(),
            'files' => 'copy',
            'history' => 'full',
        ])
        ->assertRedirect();

    Queue::assertPushed(RunMigrationJob::class);

    $run = MigrationRun::query()->sole();

    expect($run->status)->toBe(MigrationRun::STATUS_PENDING)
        ->and($run->mode)->toBe(MigrationRun::MODE_BUNDLE);
});

it('refuses to start against an install that already has content', function (): void {
    $admin = setupAdmin();

    DB::table(HostTables::CATEGORIES)->insert([
        'name' => 'Existing', 'color' => 'gray', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post('/system/migrate', [
            'mode' => 'bundle',
            'bundle_path' => tinyBundle(),
            'files' => 'copy',
            'history' => 'full',
        ])
        ->assertSessionHasErrors('mode');

    expect(MigrationRun::query()->count())->toBe(0);
});

it('runs a whole import through the job and reports what it did', function (): void {
    setupAdmin();

    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_PENDING,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => ['bundle_path' => tinyBundle()],
        'options' => ['files' => 'defer', 'history' => 'full', 'checksums' => false],
    ]);

    (new RunMigrationJob($run->id))->handle(
        app(ProjectSend\V1Migration\Source\SourceFactory::class),
        app(ProjectSend\V1Migration\Preflight\Preflight::class),
    );

    $run->refresh();

    expect($run->status)->toBe(MigrationRun::STATUS_COMPLETED);

    // The account arrived, decoded, as a client, with its v1 quota in the
    // same unit v1 meant it.
    $user = DB::table(HostTables::USERS)->where('email', 'acme@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Acme & Co.')
        ->and($user->type)->toBe('client')
        ->and($user->storage_quota_mb)->toBe(500)
        ->and($user->password)->toBe('$2y$08$abcdefghijklmnopqrstuv');

    // v1's option landed as a v2 setting, JSON-encoded the way the
    // host's cast expects to read it back.
    expect(DB::table(HostTables::SETTINGS)->where('key', 'site_name')->value('value'))
        ->toBe('"Northwind"');

    // And the baseline is on the run, which is the only thing that makes
    // undoing it possible.
    expect($run->report['baseline'])->toBeArray();
});

it('blocks a run whose source has two accounts sharing an address', function (): void {
    setupAdmin();

    $path = (new BundleBuilder)
        ->table(V1Tables::ROLES, [['id' => 1, 'name' => 'Client', 'is_system_role' => 1]])
        ->table(V1Tables::USERS, [
            ['id' => 1, 'user' => 'one', 'name' => 'One', 'email' => 'shared@example.com', 'role_id' => 1],
            ['id' => 2, 'user' => 'two', 'name' => 'Two', 'email' => 'Shared@example.com', 'role_id' => 1],
        ])
        ->write();

    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_PENDING,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => ['bundle_path' => $path],
        'options' => [],
    ]);

    (new RunMigrationJob($run->id))->handle(
        app(ProjectSend\V1Migration\Source\SourceFactory::class),
        app(ProjectSend\V1Migration\Preflight\Preflight::class),
    );

    $run->refresh();

    expect($run->status)->toBe(MigrationRun::STATUS_BLOCKED)
        // Nothing was written: a blocked run must not leave half an
        // install behind for someone to find later.
        ->and(DB::table(HostTables::USERS)->count())->toBe(1);
});
