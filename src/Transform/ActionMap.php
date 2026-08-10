<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Transform;

/**
 * v1 activity codes to v2 activity actions.
 *
 * v1 stores an integer in `tbl_actions_log.action` and looks the label
 * up in `ActionsLog::getActivitiesReferences()`. v2 stores a string that
 * Eloquent casts to a **closed** PHP enum, `App\Modules\Audit\Action`.
 *
 * That closed enum is the constraint this whole class exists to respect.
 * A package cannot add a case to it, and a value that is not a case
 * throws the moment anything reads the row — not when it is written, and
 * not only for that row: the activity log is read in pages, so one bad
 * value takes out the whole screen for every other entry beside it.
 *
 * So the rule is: map only where the meaning genuinely survives, and
 * drop the rest with a count. An unmapped code is not a failure and does
 * not stop a run — it is a line in the report saying "1,806 rows of
 * 'file marked as hidden' had no equivalent here."
 *
 * The judgement calls worth knowing about:
 *
 * - **21/22/40/46 (hidden / visible)** are dropped rather than mapped to
 *   `file.made_private`/`file.made_public`. They record v1's per-
 *   assignment `hidden` flag, which v2 has no concept of; the v2 actions
 *   that look similar are about a file's *public* flag, which is a
 *   different thing entirely. Mapping them would put confident, wrong
 *   history in front of an administrator.
 * - **7/8 both become `file.downloaded`.** v1 split the code by whether
 *   the downloader was staff or a client; v2 records that as the actor's
 *   type on the row itself, so the distinction survives.
 * - **13/14 and 16/17 and 19/20 vs 27/28** collapse the same way: v1
 *   used separate codes for users and clients, v2 has one account model.
 * - **38/39 (a request "was processed")** are dropped as ambiguous —
 *   they do not say whether the answer was yes or no, and v1 also emits
 *   the specific 44/45 for account requests.
 * - **9 (zip generated), 29 (logo changed), 30/49 (software and database
 *   updated)** have no v2 action at all.
 */
final class ActionMap
{
    /**
     * @var array<int, string>
     */
    private const MAP = [
        0 => 'setup.completed',

        1 => 'auth.login',
        24 => 'auth.login',           // cookie login; commented out in r2098 but present in older data
        43 => 'auth.login',           // via a social profile
        31 => 'auth.logout',

        2 => 'user.created',
        3 => 'user.created',
        4 => 'client.self_registered',
        42 => 'social.client_provisioned',
        13 => 'user.updated',
        14 => 'user.updated',
        16 => 'user.deleted',
        17 => 'user.deleted',
        19 => 'user.activated',
        20 => 'user.deactivated',
        27 => 'user.activated',
        28 => 'user.deactivated',
        44 => 'client.approved',
        45 => 'client.denied',

        5 => 'file.uploaded',
        6 => 'file.uploaded',
        7 => 'file.downloaded',
        8 => 'file.downloaded',
        37 => 'public_file.downloaded',
        41 => 'file.previewed',
        12 => 'file.deleted',
        32 => 'file.updated',
        33 => 'file.updated',
        25 => 'file.assigned',
        26 => 'file.assigned',
        10 => 'file.unassigned',
        11 => 'file.unassigned',

        23 => 'group.created',
        15 => 'group.updated',
        18 => 'group.deleted',

        34 => 'category.created',
        35 => 'category.renamed',
        36 => 'category.deleted',

        47 => 'settings.updated',
        48 => 'settings.updated',     // an email template, which v2 keeps under settings
    ];

    /**
     * Codes seen in the wild that this tool deliberately does not carry
     * across, with the reason shown in the run report.
     *
     * @var array<int, string>
     */
    private const DROPPED = [
        9 => 'zip file generated — v2 records zip downloads, not their generation',
        21 => 'file marked hidden — per-assignment visibility, which v2 does not have',
        22 => 'file marked visible — per-assignment visibility, which v2 does not have',
        29 => 'branding logo changed — branding is a separate module in v2',
        30 => 'ProjectSend updated',
        38 => 'account request processed — does not record the outcome',
        39 => 'membership requests processed — does not record the outcome',
        40 => 'file hidden for everyone — per-assignment visibility, which v2 does not have',
        46 => 'file hidden for everyone — per-assignment visibility, which v2 does not have',
        49 => 'database updated',
    ];

    public static function for(int $code): ?string
    {
        return self::MAP[$code] ?? null;
    }

    /**
     * Why a code was not carried across. Falls back to a generic reason
     * for codes this tool has never seen — a v1 fork, or a code added
     * after r2098 — so an unknown integer is still explained in the
     * report rather than silently vanishing.
     */
    public static function dropReason(int $code): string
    {
        return self::DROPPED[$code] ?? "unrecognised v1 activity code {$code}";
    }

    /**
     * v1 code => the ActivityOrigin v2 should record.
     *
     * Only one code is not `ui`: an anonymous download of a public file
     * did not come from a signed-in session, and v2 has a vocabulary for
     * that. Getting this right matters because the download screens
     * filter on it.
     */
    public static function origin(int $code): string
    {
        return $code === 37 ? 'public' : 'ui';
    }

    /**
     * Whether this code's `affected_file` should become the row's
     * subject. v1 stores affected_file and affected_account as bare
     * integers with no foreign key, and routinely points them at rows
     * that no longer exist — code 12 (file deleted) most obviously,
     * where the file is gone by definition.
     */
    public static function subjectIsFile(int $code): bool
    {
        return in_array(self::for($code), [
            'file.uploaded',
            'file.downloaded',
            'public_file.downloaded',
            'file.previewed',
            'file.deleted',
            'file.updated',
            'file.assigned',
            'file.unassigned',
        ], true);
    }

    public static function subjectIsAccount(int $code): bool
    {
        return in_array(self::for($code), [
            'user.created',
            'user.updated',
            'user.deleted',
            'user.activated',
            'user.deactivated',
            'client.self_registered',
            'client.approved',
            'client.denied',
            'social.client_provisioned',
        ], true);
    }
}
