<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\IdMap;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Phases\CategoriesPhase;
use ProjectSend\V1Migration\Source\BundleSource;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Tests\Support\BundleBuilder;

/**
 * @param  list<array{id: int, parent: int|null, name: string}>  $rows
 */
function importCategories(array $rows): MigrationContext
{
    $path = (new BundleBuilder)->table(
        V1Tables::CATEGORIES,
        array_map(
            static fn (array $row): array => $row + ['timestamp' => '2026-01-01 00:00:00'],
            $rows,
        ),
    )->write();

    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_RUNNING,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => ['bundle_path' => $path],
        'options' => [],
    ]);

    $context = new MigrationContext($run, new BundleSource($path), new IdMap($run->id));

    (new CategoriesPhase)->chunk($context, 0);
    $context->flushNotes();

    return $context;
}

/**
 * @return list<string>
 */
function importedCategoryNames(): array
{
    return DB::table(HostTables::CATEGORIES)->orderBy('id')->pluck('name')->all();
}

it('names every nested category after its whole ancestry', function (): void {
    importCategories([
        ['id' => 1, 'parent' => null, 'name' => 'Clients'],
        ['id' => 2, 'parent' => 1, 'name' => 'Acme'],
        ['id' => 3, 'parent' => 2, 'name' => 'Invoices'],
        ['id' => 4, 'parent' => 1, 'name' => 'Globex'],
        ['id' => 5, 'parent' => 4, 'name' => 'Invoices'],
        ['id' => 6, 'parent' => null, 'name' => 'Archive'],
        ['id' => 7, 'parent' => 6, 'name' => 'Invoices'],
    ]);

    expect(importedCategoryNames())->toBe([
        'Clients',
        'Clients / Acme',
        'Clients / Acme / Invoices',
        'Clients / Globex',
        'Clients / Globex / Invoices',
        'Archive',
        'Archive / Invoices',
    ]);
});

it('does not depend on the order rows arrive in', function (): void {
    // The reason the leaf-name-with-collision-suffix approach was
    // dropped: it gave the bare name to whichever category v1 happened
    // to have created first, and nothing about the result explained why.
    importCategories([
        ['id' => 9, 'parent' => 8, 'name' => 'Invoices'],
        ['id' => 8, 'parent' => null, 'name' => 'Archive'],
        ['id' => 3, 'parent' => 2, 'name' => 'Invoices'],
        ['id' => 2, 'parent' => null, 'name' => 'Clients'],
    ]);

    expect(importedCategoryNames())->toEqualCanonicalizing([
        'Clients',
        'Clients / Invoices',
        'Archive',
        'Archive / Invoices',
    ]);
});

it('leaves a root category with its own name', function (): void {
    importCategories([
        ['id' => 1, 'parent' => null, 'name' => 'Finance'],
        ['id' => 2, 'parent' => 0, 'name' => 'Templates'],
    ]);

    expect(importedCategoryNames())->toBe(['Finance', 'Templates']);
});

it('imports every v1 category, so no file loses a tag', function (): void {
    $context = importCategories([
        ['id' => 1, 'parent' => null, 'name' => 'A'],
        ['id' => 2, 'parent' => 1, 'name' => 'B'],
        ['id' => 3, 'parent' => 2, 'name' => 'C'],
    ]);

    expect(DB::table(HostTables::CATEGORIES)->count())->toBe(3)
        ->and($context->run->report['categories']['imported'])->toBe(3)
        ->and($context->run->report['categories']['renamed_to_their_full_path'])->toBe(2)
        ->and($context->run->report['categories']['v1_tree_depth'])->toBe(3);

    foreach ([1, 2, 3] as $sourceId) {
        expect($context->idMap->lookup(MigrationIdMap::ENTITY_CATEGORY, $sourceId))->not->toBeNull();
    }
});

it('decodes the entities v1 stored in category names', function (): void {
    importCategories([
        ['id' => 1, 'parent' => null, 'name' => 'Regi&oacute;n'],
        ['id' => 2, 'parent' => 1, 'name' => 'Dise&ntilde;o'],
    ]);

    expect(importedCategoryNames())->toBe(['Región', 'Región / Diseño']);
});

it('keeps both when two siblings share a name and the paths collide', function (): void {
    // v1 has no unique constraint on the name, so identical siblings —
    // and therefore identical paths — are possible. Losing one would
    // silently move its files onto the other.
    $context = importCategories([
        ['id' => 1, 'parent' => null, 'name' => 'Clients'],
        ['id' => 2, 'parent' => 1, 'name' => 'Acme'],
        ['id' => 3, 'parent' => 1, 'name' => 'Acme'],
    ]);

    expect(importedCategoryNames())->toBe(['Clients', 'Clients / Acme', 'Clients / Acme (2)'])
        ->and($context->run->report['categories']['identical_paths_suffixed'])->toBe(1);
});

it('does not collide with a category the install already had', function (): void {
    DB::table(HostTables::CATEGORIES)->insert([
        'name' => 'Finance', 'color' => 'gray', 'created_at' => now(), 'updated_at' => now(),
    ]);

    importCategories([['id' => 1, 'parent' => null, 'name' => 'Finance']]);

    expect(importedCategoryNames())->toBe(['Finance', 'Finance (2)']);
});

it('drops leading ancestors rather than overflowing the column', function (): void {
    // v2's categories.name is a varchar(255). The deepest part is the
    // specific one, so the front of the path is what gives way.
    $long = str_repeat('x', 90);

    importCategories([
        ['id' => 1, 'parent' => null, 'name' => $long.'-one'],
        ['id' => 2, 'parent' => 1, 'name' => $long.'-two'],
        ['id' => 3, 'parent' => 2, 'name' => $long.'-three'],
    ]);

    $names = importedCategoryNames();

    expect(mb_strlen($names[2]))->toBeLessThanOrEqual(255)
        ->and($names[2])->toStartWith('… / ')
        ->and($names[2])->toEndWith('-three');
});

it('survives a category whose parent is not there', function (): void {
    $context = importCategories([
        ['id' => 1, 'parent' => 99, 'name' => 'Orphan'],
        ['id' => 2, 'parent' => null, 'name' => 'Root'],
    ]);

    expect(importedCategoryNames())->toBe(['Orphan', 'Root'])
        ->and($context->run->report['categories']['skipped'])
        ->toHaveKey('parent category missing; imported without its ancestry');
});

it('survives a parent loop instead of hanging', function (): void {
    // Nothing in v1 stops `parent` pointing back up the chain.
    importCategories([
        ['id' => 1, 'parent' => 2, 'name' => 'One'],
        ['id' => 2, 'parent' => 1, 'name' => 'Two'],
    ]);

    expect(DB::table(HostTables::CATEGORIES)->count())->toBe(2);
});
