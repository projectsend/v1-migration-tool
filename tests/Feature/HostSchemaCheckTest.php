<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\Preflight\Finding;
use ProjectSend\V1Migration\Preflight\FreshInstallCheck;
use ProjectSend\V1Migration\Preflight\HostSchemaCheck;

it('passes against a host that matches the declared contract', function (): void {
    expect((new HostSchemaCheck)->run())->toBe([]);
});

it('blocks when a table the importer writes is missing', function (): void {
    Schema::drop('client_custom_field_values');

    $findings = (new HostSchemaCheck)->run();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->level)->toBe(Finding::BLOCKER)
        ->and($findings[0]->code)->toBe('host.table_missing')
        ->and($findings[0]->context['table'])->toBe('client_custom_field_values');
});

it('blocks when a column the importer writes is missing, and names it', function (): void {
    Schema::table('files', function ($table): void {
        $table->dropColumn('checksum');
    });

    $findings = (new HostSchemaCheck)->run();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('host.columns_missing')
        ->and($findings[0]->context['columns'])->toBe(['checksum'])
        // The message has to be actionable on its own: it is read in a
        // terminal and in a web report, without the context array.
        ->and($findings[0]->message)->toContain('checksum');
});

it('does not require host columns the importer leaves at their default', function (): void {
    // commentable and previous_file_id exist on the host but are never
    // written by the import — dropping them must not be a blocker, or
    // the contract would fossilise columns this tool has no opinion on.
    Schema::table('files', function ($table): void {
        $table->dropColumn(['commentable', 'previous_file_id']);
    });

    expect((new HostSchemaCheck)->run())->toBe([]);
});

it('treats a single setup administrator as a fresh install', function (): void {
    DB::table(HostTables::USERS)->insert([
        'type' => 'staff',
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((new FreshInstallCheck)->run())->toBe([]);
});

it('blocks a run against an install that already has accounts', function (): void {
    foreach (['admin@example.com', 'someone@example.com'] as $email) {
        DB::table(HostTables::USERS)->insert([
            'type' => 'staff',
            'name' => 'Someone',
            'email' => $email,
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $findings = (new FreshInstallCheck)->run();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('host.not_fresh_users')
        ->and($findings[0]->context['count'])->toBe(2);
});

it('blocks a run against an install that already has content', function (): void {
    DB::table(HostTables::CATEGORIES)->insert([
        'name' => 'Existing',
        'color' => 'gray',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $findings = (new FreshInstallCheck)->run();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe('host.not_fresh')
        ->and($findings[0]->context['table'])->toBe('categories');
});
