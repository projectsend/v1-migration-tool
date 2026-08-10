<?php

declare(strict_types=1);

use ProjectSend\V1Migration\IdMap;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Source\BundleSource;
use ProjectSend\V1Migration\Tests\Support\BundleBuilder;

function contextForNotes(): MigrationContext
{
    $path = (new BundleBuilder)->write();

    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_RUNNING,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => ['bundle_path' => $path],
        'options' => [],
    ]);

    return new MigrationContext($run, new BundleSource($path), new IdMap($run->id));
}

it('adds counters up across flushes', function (): void {
    $context = contextForNotes();

    $context->count('files', 'imported', 500);
    $context->flushNotes();
    $context->count('files', 'imported', 300);
    $context->flushNotes();

    expect($context->run->report['files']['imported'])->toBe(800);
});

it('tallies each skip reason separately', function (): void {
    $context = contextForNotes();

    $context->skipped('files', 'the file row exists but its bytes are gone');
    $context->skipped('files', 'the file row exists but its bytes are gone');
    $context->skipped('files', 'encrypted at rest in v1; v2 has no equivalent');
    $context->flushNotes();

    expect($context->run->report['files']['skipped'])->toBe([
        'the file row exists but its bytes are gone' => 2,
        'encrypted at rest in v1; v2 has no equivalent' => 1,
    ]);
});

it('keeps a recorded fact as it is instead of adding it up', function (): void {
    // The settings phase records a map of v1 option name => why it was
    // not carried. Treating that like a counter cast every reason to 0,
    // so the report said which options were dropped and lost the only
    // part that mattered.
    $context = contextForNotes();

    $context->set('settings', 'not_carried', [
        'base_uri' => 'install state',
        'mail_smtp_host' => 'carried by email settings (Settings → Email)',
    ]);
    $context->flushNotes();

    expect($context->run->report['settings']['not_carried'])->toBe([
        'base_uri' => 'install state',
        'mail_smtp_host' => 'carried by email settings (Settings → Email)',
    ]);
});

it('lets a later flush replace a recorded fact rather than merge it', function (): void {
    $context = contextForNotes();

    $context->set('categories', 'v1_tree_depth', 2);
    $context->flushNotes();
    $context->set('categories', 'v1_tree_depth', 4);
    $context->flushNotes();

    expect($context->run->report['categories']['v1_tree_depth'])->toBe(4);
});

it('keeps counters, skips and facts side by side in one phase', function (): void {
    $context = contextForNotes();

    $context->count('files', 'imported');
    $context->skipped('files', 'no bytes on disk');
    $context->set('files', 'strategy', 'hardlink');
    $context->flushNotes();

    expect($context->run->report['files'])->toBe([
        'imported' => 1,
        'skipped' => ['no bytes on disk' => 1],
        'strategy' => 'hardlink',
    ]);
});

it('writes nothing when there is nothing to say', function (): void {
    $context = contextForNotes();
    $context->run->report = ['preflight' => []];
    $context->run->save();

    $context->flushNotes();

    expect($context->run->report)->toBe(['preflight' => []]);
});
