<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Preflight;

use ProjectSend\V1Migration\Source\MigrationSource;
use ProjectSend\V1Migration\Source\V1FilePath;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Transform\OptionMap;

/**
 * Everything worth knowing about a v1 install before importing it.
 *
 * Reads, counts, and refuses — it never writes to either side. Two kinds
 * of output:
 *
 * **Blockers** are things that would silently lose or corrupt data, and
 * no flag overrides them. There is exactly one class of those, and it is
 * the reason this check exists at all: v1 signs in with a username and
 * does not require `users.email` to be unique, while v2 signs in with an
 * email address and does. Two v1 accounts sharing an address cannot both
 * become v2 accounts, and picking a winner for someone is not a
 * migration — it is deciding which of their clients loses access.
 *
 * **Acknowledgements** are v1 features v2 has no equivalent for. The
 * rows are listed, the operator confirms they have read the list, and
 * the import skips them. Guessing would be worse: decrypting somebody's
 * files at rest because their new install cannot encrypt them is a
 * security decision, not a data-format decision.
 *
 * Scans are bounded on purpose. `users`, `files` and `files_relations`
 * are read in full because what they contain decides whether the run may
 * proceed. `downloads` and `actions_log` are only counted — they are the
 * tables with millions of rows, and nothing in them can block a run.
 */
final class SourcePreflight
{
    private const SAMPLE_LIMIT = 25;

    private const CHUNK = 1000;

    /**
     * @return list<Finding>
     */
    public function run(MigrationSource $source): array
    {
        return [
            ...$this->checkEmails($source),
            ...$this->checkFiles($source),
            ...$this->checkAssignments($source),
            ...$this->checkRoles($source),
            ...$this->checkOptions($source),
            ...$this->describe($source),
        ];
    }

    /**
     * @return list<Finding>
     */
    private function checkEmails(MigrationSource $source): array
    {
        $findings = [];
        $seen = [];
        $duplicates = [];
        $blank = [];
        $invalid = [];
        $twoFactor = 0;
        $afterId = 0;

        while (($rows = $source->rows(V1Tables::USERS, $afterId, self::CHUNK)) !== []) {
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                $email = trim((string) ($row['email'] ?? ''));
                $username = (string) ($row['user'] ?? $afterId);

                if ((int) ($row['totp_enabled'] ?? 0) === 1) {
                    $twoFactor++;
                }

                if ($email === '') {
                    $blank[] = $username;

                    continue;
                }

                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $invalid[] = $username.' <'.$email.'>';

                    continue;
                }

                $key = mb_strtolower($email);

                if (isset($seen[$key])) {
                    $duplicates[$key][] = $username;
                } else {
                    $seen[$key] = $username;
                    $duplicates[$key] ??= [$username];
                }
            }
        }

        $collisions = array_filter($duplicates, static fn (array $users): bool => count($users) > 1);

        if ($collisions !== []) {
            $findings[] = Finding::blocker(
                'source.duplicate_emails',
                sprintf(
                    '%d email address(es) are used by more than one v1 account. v1 signs in by username and allows this; v2 signs in by email and cannot. Give each account its own address in v1, then run this again.',
                    count($collisions),
                ),
                [
                    'count' => count($collisions),
                    'sample' => array_slice(
                        array_map(
                            static fn (string $email, array $users): string => $email.': '.implode(', ', $users),
                            array_keys($collisions),
                            $collisions,
                        ),
                        0,
                        self::SAMPLE_LIMIT,
                    ),
                ],
            );
        }

        if ($blank !== []) {
            $findings[] = Finding::blocker(
                'source.blank_emails',
                sprintf(
                    '%d v1 account(s) have no email address. In v2 the email address *is* the login, so these accounts would have no way to sign in.',
                    count($blank),
                ),
                ['count' => count($blank), 'sample' => array_slice($blank, 0, self::SAMPLE_LIMIT)],
            );
        }

        if ($invalid !== []) {
            $findings[] = Finding::blocker(
                'source.invalid_emails',
                sprintf('%d v1 account(s) have an address that is not a valid email.', count($invalid)),
                ['count' => count($invalid), 'sample' => array_slice($invalid, 0, self::SAMPLE_LIMIT)],
            );
        }

        if ($twoFactor > 0) {
            $findings[] = Finding::note(
                'source.two_factor',
                sprintf(
                    '%d account(s) have two-factor authentication enabled. The secrets are encrypted with this v1 install\'s key and cannot travel; those people will be asked to enrol again.',
                    $twoFactor,
                ),
                ['count' => $twoFactor],
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkFiles(MigrationSource $source): array
    {
        $findings = [];
        $encrypted = [];
        $external = [];
        $limited = 0;
        $missing = [];
        $afterId = 0;
        $dated = $source->manifest()->uploadsOrganizedByDate;

        while (($rows = $source->rows(V1Tables::FILES, $afterId, self::CHUNK)) !== []) {
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];

                if ((int) ($row['encrypted'] ?? 0) === 1) {
                    $encrypted[] = $afterId;

                    continue;
                }

                if (($row['storage_type'] ?? 'local') !== 'local') {
                    $external[] = $afterId;

                    continue;
                }

                if ((int) ($row['download_limit_enabled'] ?? 0) === 1) {
                    $limited++;
                }

                if ($source->manifest()->filesIncluded
                    && ! $source->fileExists(V1FilePath::for($row, $dated))) {
                    $missing[] = $afterId;
                }
            }
        }

        if ($encrypted !== []) {
            $findings[] = Finding::acknowledge(
                'source.encrypted_files',
                sprintf(
                    '%d file(s) are encrypted at rest. ProjectSend v2 has no at-rest encryption, and the per-file keys are wrapped by this install\'s ENCRYPTION_MASTER_KEY — which is not in the database and cannot be recovered from it. These files will be skipped.',
                    count($encrypted),
                ),
                ['count' => count($encrypted), 'sample' => array_slice($encrypted, 0, self::SAMPLE_LIMIT)],
            );
        }

        if ($external !== []) {
            $findings[] = Finding::acknowledge(
                'source.external_storage',
                sprintf(
                    '%d file(s) are stored on S3, GCS or Azure rather than on disk. v1 configures external storage per file; v2 has a single bucket for everything. These files will be skipped.',
                    count($external),
                ),
                ['count' => count($external), 'sample' => array_slice($external, 0, self::SAMPLE_LIMIT)],
            );
        }

        if ($limited > 0) {
            $findings[] = Finding::acknowledge(
                'source.download_limits',
                sprintf(
                    '%d file(s) have a download limit. v2 caps downloads on share links, not on files, so the files import and the limits do not.',
                    $limited,
                ),
                ['count' => $limited],
            );
        }

        if ($missing !== []) {
            $findings[] = Finding::note(
                'source.missing_bytes',
                sprintf(
                    '%d file row(s) have no bytes on disk. v2 has no way to represent a file whose content is gone, so these rows will be skipped and listed in the report.',
                    count($missing),
                ),
                ['count' => count($missing), 'sample' => array_slice($missing, 0, self::SAMPLE_LIMIT)],
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkAssignments(MigrationSource $source): array
    {
        $hidden = 0;
        $afterId = 0;

        while (($rows = $source->rows(V1Tables::FILES_RELATIONS, $afterId, self::CHUNK)) !== []) {
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];

                if ((int) ($row['hidden'] ?? 0) === 1) {
                    $hidden++;
                }
            }
        }

        if ($hidden === 0) {
            return [];
        }

        return [Finding::acknowledge(
            'source.hidden_assignments',
            sprintf(
                '%d assignment(s) are marked hidden. v1 could assign a file to someone and hide it from them; v2 has no such state, so these assignments will not be created.',
                $hidden,
            ),
            ['count' => $hidden],
        )];
    }

    /**
     * @return list<Finding>
     */
    private function checkRoles(MigrationSource $source): array
    {
        $custom = [];
        $clientRoleRenamed = false;
        $afterId = 0;

        while (($rows = $source->rows(V1Tables::ROLES, $afterId, self::CHUNK)) !== []) {
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];

                if ((int) ($row['is_system_role'] ?? 0) === 0) {
                    $custom[] = (string) $row['name'];
                }

                if ((int) $row['id'] === 1 && (string) $row['name'] !== 'Client') {
                    $clientRoleRenamed = true;
                }
            }
        }

        $findings = [];

        if ($custom !== []) {
            $findings[] = Finding::note(
                'source.custom_roles',
                sprintf(
                    '%d custom role(s) will be recreated with whichever of their permissions v2 still has.',
                    count($custom),
                ),
                ['count' => count($custom), 'sample' => array_slice($custom, 0, self::SAMPLE_LIMIT)],
            );
        }

        if ($clientRoleRenamed) {
            // v1 decides whether an account is a client by the role's
            // *name* being literally "Client" (Roles::isClientRole()),
            // not by its id. A renamed role changes v1's own behaviour,
            // so the importer cannot silently assume id 1 means client.
            $findings[] = Finding::acknowledge(
                'source.client_role_renamed',
                'v1\'s built-in Client role has been renamed. v1 decides who is a client by that role\'s name, so accounts holding it may not be treated as clients. Check the report after importing.',
            );
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function checkOptions(MigrationSource $source): array
    {
        $converted = OptionMap::convert($source->options());
        $noEquivalent = array_filter(
            $converted['unmapped'],
            static fn (string $reason): bool => $reason === 'no equivalent in v2',
        );

        if ($noEquivalent === []) {
            return [];
        }

        return [Finding::note(
            'source.unmapped_options',
            sprintf(
                '%d v1 setting(s) have no equivalent in v2 and will be left at v2\'s defaults.',
                count($noEquivalent),
            ),
            ['count' => count($noEquivalent), 'keys' => array_keys($noEquivalent)],
        )];
    }

    /**
     * @return list<Finding>
     */
    private function describe(MigrationSource $source): array
    {
        $manifest = $source->manifest();

        return [Finding::note(
            'source.summary',
            sprintf(
                'ProjectSend %s: %s accounts, %s files, %s downloads, %s activity entries.',
                $manifest->version ?? 'v1',
                number_format($source->count(V1Tables::USERS)),
                number_format($source->count(V1Tables::FILES)),
                number_format($source->count(V1Tables::DOWNLOADS)),
                number_format($source->count(V1Tables::ACTIONS_LOG)),
            ),
            $manifest->toArray(),
        )];
    }
}
