<?php

declare(strict_types=1);

use ProjectSend\V1Migration\Transform\ActionMap;

it('maps the codes a real install is full of', function (): void {
    // The codes present across the three v1 fixtures, in descending
    // order of how many rows carry them. The three download codes are
    // not here on purpose — see the drop test below.
    expect(ActionMap::for(1))->toBe('auth.login')
        ->and(ActionMap::for(31))->toBe('auth.logout')
        ->and(ActionMap::for(25))->toBe('file.assigned')
        ->and(ActionMap::for(5))->toBe('file.uploaded')
        ->and(ActionMap::for(32))->toBe('file.updated')
        ->and(ActionMap::for(41))->toBe('file.previewed')
        ->and(ActionMap::for(12))->toBe('file.deleted')
        ->and(ActionMap::for(47))->toBe('settings.updated')
        ->and(ActionMap::for(34))->toBe('category.created')
        ->and(ActionMap::for(23))->toBe('group.created');
});

it('collapses v1 codes that only differ by whether the actor was staff or a client', function (): void {
    // v2 records the actor's type on the row, so the split is not lost.
    // Each pair is asserted non-null first: 7/8 used to be the headline
    // example here and are now both null, which would have let this pass
    // by agreeing about nothing.
    foreach ([[5, 6], [13, 14], [16, 17], [32, 33]] as [$staff, $client]) {
        expect(ActionMap::for($staff))->not->toBeNull()
            ->and(ActionMap::for($staff))->toBe(ActionMap::for($client));
    }
});

it('drops the three download codes, because v1 records every download twice', function (): void {
    // One v1 download writes a tbl_downloads row AND an actions_log row
    // (Download.php calls record_new_download() and then logs 7/8;
    // download.php pairs record_new_download(0, …) with 37). Importing
    // both doubled every download count in v2, which reads all three
    // download actions. DownloadsPhase is the single source now.
    foreach ([7, 8, 37] as $code) {
        expect(ActionMap::for($code))->toBeNull()
            ->and(ActionMap::dropReason($code))->toContain('downloads phase');
    }
});

it('never maps any code to a download action', function (): void {
    // The guard the fix actually needs. Asserting the three known codes
    // are null says nothing about a fourth being added later, and the
    // failure mode is silent: a mapped download code does not error, it
    // just makes every count wrong again.
    foreach (range(0, 70) as $code) {
        expect(ActionMap::for($code))->not->toBeIn([
            'file.downloaded',
            'public_file.downloaded',
            'share_link.downloaded',
        ]);
    }
});

it('refuses to map per-assignment visibility onto the public flag', function (): void {
    // 21/22/40/46 record v1's files_relations.hidden. v2's
    // file.made_public / file.made_private are about something else
    // entirely, and mapping these there would put confident, wrong
    // history in front of an administrator.
    foreach ([21, 22, 40, 46] as $code) {
        expect(ActionMap::for($code))->toBeNull()
            ->and(ActionMap::dropReason($code))->toContain('visibility');
    }
});

it('drops outcome-less "was processed" codes', function (): void {
    foreach ([38, 39] as $code) {
        expect(ActionMap::for($code))->toBeNull()
            ->and(ActionMap::dropReason($code))->toContain('outcome');
    }
});

it('explains a code it has never seen rather than dropping it silently', function (): void {
    expect(ActionMap::for(9999))->toBeNull()
        ->and(ActionMap::dropReason(9999))->toBe('unrecognised v1 activity code 9999');
});

it('records every code it still carries as coming from the web UI', function (): void {
    // 37 was the one exception and is no longer mapped here at all:
    // DownloadsPhase sets that origin from the `anonymous` column, which
    // is a better source than the action code was.
    expect(ActionMap::origin(1))->toBe('ui')
        ->and(ActionMap::origin(5))->toBe('ui')
        ->and(ActionMap::origin(41))->toBe('ui');
});

it('knows which codes carry a file subject and which carry an account', function (): void {
    expect(ActionMap::subjectIsFile(5))->toBeTrue()
        ->and(ActionMap::subjectIsFile(41))->toBeTrue()
        ->and(ActionMap::subjectIsFile(1))->toBeFalse()
        ->and(ActionMap::subjectIsAccount(13))->toBeTrue()
        ->and(ActionMap::subjectIsAccount(5))->toBeFalse();
});
