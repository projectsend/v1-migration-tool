<?php

declare(strict_types=1);

use ProjectSend\V1Migration\Transform\ActionMap;

it('maps the codes a real install is full of', function (): void {
    // The twenty-one codes present across the three v1 fixtures, in
    // descending order of how many rows carry them.
    expect(ActionMap::for(8))->toBe('file.downloaded')       // client download
        ->and(ActionMap::for(1))->toBe('auth.login')
        ->and(ActionMap::for(37))->toBe('public_file.downloaded')
        ->and(ActionMap::for(31))->toBe('auth.logout')
        ->and(ActionMap::for(25))->toBe('file.assigned')
        ->and(ActionMap::for(5))->toBe('file.uploaded')
        ->and(ActionMap::for(32))->toBe('file.updated')
        ->and(ActionMap::for(7))->toBe('file.downloaded')
        ->and(ActionMap::for(41))->toBe('file.previewed')
        ->and(ActionMap::for(12))->toBe('file.deleted')
        ->and(ActionMap::for(47))->toBe('settings.updated')
        ->and(ActionMap::for(34))->toBe('category.created')
        ->and(ActionMap::for(23))->toBe('group.created');
});

it('collapses v1 codes that only differ by whether the actor was staff or a client', function (): void {
    // v2 records the actor's type on the row, so the split is not lost.
    expect(ActionMap::for(7))->toBe(ActionMap::for(8))
        ->and(ActionMap::for(5))->toBe(ActionMap::for(6))
        ->and(ActionMap::for(13))->toBe(ActionMap::for(14))
        ->and(ActionMap::for(16))->toBe(ActionMap::for(17))
        ->and(ActionMap::for(32))->toBe(ActionMap::for(33));
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

it('records an anonymous public download as coming from outside a session', function (): void {
    expect(ActionMap::origin(37))->toBe('public')
        ->and(ActionMap::origin(8))->toBe('ui')
        ->and(ActionMap::origin(1))->toBe('ui');
});

it('knows which codes carry a file subject and which carry an account', function (): void {
    expect(ActionMap::subjectIsFile(8))->toBeTrue()
        ->and(ActionMap::subjectIsFile(1))->toBeFalse()
        ->and(ActionMap::subjectIsAccount(13))->toBeTrue()
        ->and(ActionMap::subjectIsAccount(5))->toBeFalse();
});
