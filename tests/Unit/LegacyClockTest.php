<?php

declare(strict_types=1);

use ProjectSend\V1Migration\Transform\LegacyClock;
use ProjectSend\V1Migration\Transform\OptionMap;

test('it reinterprets a v1 wall-clock value in the source zone', function () {
    $clock = new LegacyClock('America/Argentina/Buenos_Aires');

    // v1 wrote "14:00" meaning two in the afternoon in Buenos Aires. In
    // UTC that is five.
    expect($clock->toUtc('2024-03-07 14:00:00'))->toBe('2024-03-07 17:00:00');
});

test('it honours the source zone\'s daylight saving rather than a fixed offset', function () {
    $clock = new LegacyClock('Europe/Madrid');

    expect($clock->toUtc('2024-01-15 12:00:00'))->toBe('2024-01-15 11:00:00')
        ->and($clock->toUtc('2024-07-15 12:00:00'))->toBe('2024-07-15 10:00:00');
});

test('a UTC source is already right and comes back unchanged', function () {
    expect((new LegacyClock('UTC'))->toUtc('2024-03-07 14:00:00'))->toBe('2024-03-07 14:00:00');
});

// A bundle exported without a zone, or a v1 install whose option was never
// set — in both cases v1 fell back to the *server's* default, which this
// side cannot know. Reproducing today's behaviour beats inventing an offset.
test('an unknown source zone passes values through untouched', function () {
    foreach ([null, '', 'Mars/Olympus_Mons'] as $timezone) {
        $clock = new LegacyClock($timezone);

        expect($clock->hasTimezone())->toBeFalse()
            ->and($clock->toUtc('2024-03-07 14:00:00'))->toBe('2024-03-07 14:00:00');
    }
});

// v1's schema is full of its own "no date" sentinel, and of columns
// operators have edited by hand. A migration must carry a row it cannot
// parse, not abort on it.
test('it carries an unparseable value across as it found it', function () {
    $clock = new LegacyClock('Europe/Madrid');

    expect($clock->toUtc('0000-00-00 00:00:00'))->toBe('0000-00-00 00:00:00')
        ->and($clock->toUtc('not a date at all'))->toBe('not a date at all')
        ->and($clock->toUtc(null))->toBeNull()
        ->and($clock->toUtc(''))->toBe('');
});

test('the v1 timezone option maps onto the v2 setting', function () {
    $converted = OptionMap::convert(['timezone' => 'America/Argentina/Buenos_Aires']);

    expect($converted['settings'])->toBe(['timezone' => 'America/Argentina/Buenos_Aires'])
        ->and($converted['unmapped'])->toBe([]);
});

test('a zone this PHP does not know is reported rather than written', function () {
    $converted = OptionMap::convert(['timezone' => 'Mars/Olympus_Mons']);

    expect($converted['settings'])->toBe([])
        ->and($converted['unmapped'])->toHaveKey('timezone');
});

// v1's timeformat holds a raw PHP date() format string. v2 formats dates
// from the reader's locale through Intl, so there is nothing for it to
// become — see OptionMap's note on the timezone entry.
test('v1\'s timeformat is left behind deliberately', function () {
    $converted = OptionMap::convert(['timeformat' => 'd/m/Y']);

    expect($converted['settings'])->toBe([])
        ->and($converted['unmapped'])->toHaveKey('timeformat');
});
