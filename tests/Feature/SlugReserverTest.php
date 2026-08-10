<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Transform\SlugReserver;

it('suffixes collisions the way the host does', function (): void {
    $reserver = new SlugReserver('file');

    expect($reserver->reserve('Quarterly Report'))->toBe('quarterly-report')
        ->and($reserver->reserve('Quarterly Report'))->toBe('quarterly-report-2')
        ->and($reserver->reserve('quarterly report'))->toBe('quarterly-report-3');
});

it('falls back when a name slugs to nothing', function (): void {
    // Str::slug() drops every character in these, which is not an edge
    // case in a v1 install — it is every CJK title in the database, and
    // the column is NOT NULL UNIQUE.
    $reserver = new SlugReserver('file');

    expect($reserver->reserve('年度報告書'))->toBe('file')
        ->and($reserver->reserve('...'))->toBe('file-2')
        ->and($reserver->reserve(null))->toBe('file-3');
});

it('does not collide with slugs the host already has', function (): void {
    DB::table('groups')->insert([
        'name' => 'Design',
        'slug' => 'design',
        'public' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reserver = SlugReserver::seededFrom('groups', 'group');

    expect($reserver->reserve('Design'))->toBe('design-2');
});

it('hands out one slug per call so a bulk insert cannot duplicate', function (): void {
    $reserver = new SlugReserver('file');

    $slugs = [];
    for ($i = 0; $i < 500; $i++) {
        $slugs[] = $reserver->reserve('Final');
    }

    expect(array_unique($slugs))->toHaveCount(500)
        ->and($reserver->count())->toBe(500);
});
