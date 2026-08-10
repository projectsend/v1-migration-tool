<?php

declare(strict_types=1);

use ProjectSend\V1Migration\Host\HostTables;

it('deletes exactly the tables it writes', function (): void {
    // These two lists are maintained by hand and drift apart silently:
    // a phase that starts writing a new table leaves rows behind on
    // reset, and a table removed from the contract makes reset fail on
    // a table that is not there.
    expect(HostTables::deleteOrder())
        ->toEqualCanonicalizing(array_keys(HostTables::writes()));
});

it('deletes children before the rows they point at', function (): void {
    $order = array_flip(HostTables::deleteOrder());

    // users.role_id is ON DELETE RESTRICT, which is what caught this:
    // reversing the contract listing put roles first and the very first
    // undo failed halfway through.
    expect($order[HostTables::USERS])->toBeLessThan($order[HostTables::ROLES])
        ->and($order[HostTables::FILE_ASSIGNMENTS])->toBeLessThan($order[HostTables::FILES])
        ->and($order[HostTables::CATEGORY_FILE])->toBeLessThan($order[HostTables::CATEGORIES])
        ->and($order[HostTables::GROUP_MEMBERS])->toBeLessThan($order[HostTables::GROUPS])
        ->and($order[HostTables::FILES])->toBeLessThan($order[HostTables::USERS])
        ->and($order[HostTables::CLIENT_CUSTOM_FIELD_VALUES])
        ->toBeLessThan($order[HostTables::CLIENT_CUSTOM_FIELDS]);
});
