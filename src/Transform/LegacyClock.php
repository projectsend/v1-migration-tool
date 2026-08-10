<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Transform;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * v1 timestamps into real UTC.
 *
 * v1 never stored UTC. It called `date_default_timezone_set()` at
 * bootstrap with whatever was in `tbl_options.timezone` and then wrote
 * `date()` output straight into `DATETIME` columns — so a row reading
 * `2024-03-07 14:00:00` means two o'clock *in that install's zone*, with
 * nothing in the value to say which zone that was. Reading it back with a
 * different default silently reinterprets every date in the database.
 *
 * That is why `LegacyDatabaseSource` pins both connections to `+00:00`:
 * it makes the read an identity, so the wall-clock digits arrive here
 * exactly as v1 wrote them. This class is the other half — it decides
 * what those digits *mean*.
 *
 * Skipping this step is not neutral. v2 stores UTC and renders through
 * the viewer's timezone (see TimezoneRegistry in the host app), so an
 * unconverted import is correct only for as long as the destination
 * displays UTC too; the first administrator to set their timezone would
 * shift the entire imported history by that offset, in the wrong
 * direction, with no way to tell which rows had been migrated.
 *
 * A null or unrecognised source zone is a pass-through rather than a
 * guess. It means either a bundle exported without one or a v1 install
 * whose option was never set — in both cases v1 itself fell back to the
 * *server's* default, which this side cannot know. Leaving the value
 * alone reproduces exactly today's behaviour instead of inventing an
 * offset, and the import reports it rather than failing.
 */
final class LegacyClock
{
    private readonly ?string $timezone;

    public function __construct(?string $timezone)
    {
        $this->timezone = self::usable($timezone) ? $timezone : null;
    }

    public function hasTimezone(): bool
    {
        return $this->timezone !== null;
    }

    public function timezone(): ?string
    {
        return $this->timezone;
    }

    /**
     * Reinterpret a v1 wall-clock value in the source zone and return it
     * as UTC, in the same `Y-m-d H:i:s` shape the phases insert.
     *
     * Anything unparseable comes back untouched. v1's schema is full of
     * `0000-00-00 00:00:00` (its own "no date" sentinel, which MySQL in
     * strict mode will not accept and which `CarbonImmutable` refuses)
     * and of columns operators have edited by hand; a migration must not
     * abort on a row it could simply carry across as it found it.
     */
    public function toUtc(mixed $value): mixed
    {
        if ($this->timezone === null || ! is_string($value) || $value === '') {
            return $value;
        }

        // MySQL's zero date, which v1 uses as its own "no date" and which
        // `expiry_date` in particular is full of (a NOT NULL TIMESTAMP with
        // no sensible empty value — see FilesPhase). It has to be checked
        // for rather than caught: `CarbonImmutable` does not reject it, it
        // parses it into `-0001-11-30 00:14:44` and hands that back as
        // though it meant something.
        if (str_starts_with($value, '0000-00-00')) {
            return $value;
        }

        try {
            return CarbonImmutable::parse($value, $this->timezone)
                ->utc()
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $value;
        }
    }

    private static function usable(?string $timezone): bool
    {
        if ($timezone === null || $timezone === '') {
            return false;
        }

        return in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }
}

