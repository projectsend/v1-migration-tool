<?php

declare(strict_types=1);

use ProjectSend\V1Migration\IdMap;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Models\MigrationRun;

function makeRun(): MigrationRun
{
    return MigrationRun::create([
        'status' => MigrationRun::STATUS_RUNNING,
        'mode' => MigrationRun::MODE_DIRECT,
        'source' => [],
        'options' => [],
    ]);
}

it('resolves a source id to the row it became', function (): void {
    $map = new IdMap(makeRun()->id);

    $map->record(MigrationIdMap::ENTITY_USER, 41, 7);
    $map->flush();

    expect($map->lookup(MigrationIdMap::ENTITY_USER, 41))->toBe(7)
        ->and($map->lookup(MigrationIdMap::ENTITY_USER, 42))->toBeNull();
});

it('answers preloaded entities from memory, including writes made after the preload', function (): void {
    $run = makeRun();
    $map = new IdMap($run->id);

    $map->record(MigrationIdMap::ENTITY_GROUP, 1, 100);
    $map->flush();

    $map->preload(MigrationIdMap::ENTITY_GROUP);

    // A phase preloads once and then keeps writing; the cache has to
    // absorb its own flushes or the second half of the phase resolves
    // nothing it just created.
    $map->record(MigrationIdMap::ENTITY_GROUP, 2, 200);
    $map->flush();

    expect($map->lookup(MigrationIdMap::ENTITY_GROUP, 1))->toBe(100)
        ->and($map->lookup(MigrationIdMap::ENTITY_GROUP, 2))->toBe(200);
});

it('resolves a chunk of ids in one pass and drops the unmapped ones', function (): void {
    $map = new IdMap(makeRun()->id);

    $map->record(MigrationIdMap::ENTITY_FILE, 10, 1);
    $map->record(MigrationIdMap::ENTITY_FILE, 11, 2);
    $map->skip(MigrationIdMap::ENTITY_FILE, 12, 'bytes missing on disk');
    $map->flush();

    expect($map->lookupMany(MigrationIdMap::ENTITY_FILE, [10, 11, 12, 13]))
        ->toBe([10 => 1, 11 => 2]);
});

it('records a skipped row so a resumed run does not reconsider it', function (): void {
    $map = new IdMap(makeRun()->id);

    $map->skip(MigrationIdMap::ENTITY_FILE, 12, 'bytes missing on disk');
    $map->flush();

    expect($map->alreadySeen(MigrationIdMap::ENTITY_FILE, [11, 12]))->toBe([12 => true]);

    $row = MigrationIdMap::query()->where('source_id', 12)->sole();

    expect($row->status)->toBe(MigrationIdMap::STATUS_SKIPPED)
        ->and($row->target_id)->toBeNull()
        ->and($row->note)->toBe('bytes missing on disk');
});

it('keeps two runs of the same source apart', function (): void {
    $first = new IdMap(makeRun()->id);
    $second = new IdMap(makeRun()->id);

    $first->record(MigrationIdMap::ENTITY_USER, 1, 10);
    $first->flush();

    expect($second->lookup(MigrationIdMap::ENTITY_USER, 1))->toBeNull();
});
