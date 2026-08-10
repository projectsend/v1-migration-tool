<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Transform;

/**
 * v1's options table to v2's settings.
 *
 * v1 keeps roughly 180 rows in a flat `name`/`value` bag with no unique
 * key and no types. v2 has 43 settings behind a closed enum that
 * type-checks every write. Most of the difference is not data loss — it
 * is v1 options that describe machinery v2 doesn't have (its own
 * thumbnail cache, its own cron, its own captcha integrations), or
 * install state that would be actively wrong to copy (`base_uri`,
 * `database_version`, the update-checker's cached results).
 *
 * Three rules this class exists to enforce:
 *
 * **Nothing is enumerated as an allow-list.** Junk rows accumulate in
 * real v1 installs — `section`, `submit` and `updated` have been seen,
 * written straight out of a settings form post — and v1 has no unique
 * key on `name`, so duplicates happen too. An unrecognised key is
 * reported, never a failure.
 *
 * **Values are typed on the way out.** v2's Settings service throws on a
 * wrong PHP type, and this package writes the settings table directly,
 * so the coercion has to be right here rather than caught downstream.
 *
 * **`clients_auto_group` cannot be resolved yet.** It holds a v1 group
 * id, and groups are imported four phases after settings. It is returned
 * separately so the groups phase can write it once the id is known —
 * see `deferredAutoGroup()`.
 *
 * One unit trap, verified against v1's own code rather than its column
 * type: `clients_default_disk_quota` and `users.max_disk_quota` are
 * **megabytes**, despite the column being `bigint unsigned`. v1 converts
 * with `$max_disk_quota * 1048576` at the point of use
 * (includes/functions.php). Treating them as bytes would divide every
 * quota by a million and silently make every client unlimited.
 */
final class OptionMap
{
    /**
     * v1 option name => [v2 setting key, coercion].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const MAP = [
        'this_install_title' => ['site_name', 'string'],
        'clients_can_register' => ['clients_can_register', 'bool'],
        'clients_auto_approve' => ['clients_auto_approve', 'bool'],
        'clients_default_disk_quota' => ['default_client_storage_quota_mb', 'int'],
        'allowed_file_types' => ['allowed_upload_extensions', 'extensions'],
        'privacy_noindex_site' => ['discourage_search_indexing', 'bool'],
        'public_listing_page_enable' => ['public_listing_enabled', 'bool'],
        'cron_delete_expired_files' => ['expired_files_auto_delete_enabled', 'bool'],
        'cron_delete_orphan_files' => ['orphan_files_auto_delete_enabled', 'bool'],
        'notifications_send_when_saving_files' => ['email_notifications_enabled', 'bool'],
        'admin_email_address' => ['admin_notification_emails', 'email_list'],

        // v1 and v2 already share these vocabularies — v2's settings were
        // written for v1 parity — but the value is validated rather than
        // trusted, because a hand-edited options row is a real thing.
        'clients_can_select_group' => ['clients_can_select_group', 'select_group'],
        'file_types_limit_to' => ['upload_type_restriction', 'upload_restriction'],
        'privacy_record_downloads_ip_address' => ['download_ip_logging', 'ip_logging'],

        // Inverted: v1 asks whether to *prevent* the check.
        'prevent_updates_check' => ['check_for_updates', 'inverted_bool'],

        'two_factor_required' => ['two_factor_enforcement', 'two_factor'],
        'selected_clients_template' => ['theme', 'theme'],
    ];

    /**
     * Options carried by a different mechanism in v2, matched by prefix.
     * Reported as moved rather than lost, so an administrator checking
     * the report for "where did my SMTP settings go" gets an answer.
     *
     * @var array<string, string>
     */
    private const ELSEWHERE = [
        'mail_' => 'email settings (Settings → Email)',
        'ldap_' => 'LDAP settings (Settings → LDAP)',
        'google_' => 'social login (Settings → Social login)',
        'oidc_' => 'social login (Settings → Social login)',
        'x_' => 'social login (Settings → Social login)',
        'email_' => 'email templates (Settings → Email templates)',
    ];

    /**
     * State that belongs to the v1 installation itself. Copying any of
     * it would be worse than dropping it: `base_uri` would point v2 at
     * v1's URL, `database_version` would convince the host it had
     * already run migrations it hasn't, and the cached update-check
     * results would announce a v1 release as available.
     *
     * @var list<string>
     */
    private const INSTALL_STATE = [
        'base_uri',
        'database_version',
        'last_update',
        'permission_system_version',
        'role_system_version',
        'auto_create_missing_permissions',
        'enable_custom_roles',
        'legacy_role_levels_removed',
        'role_level_columns_removed',
        'roles_migration_to_id_completed',
        'users_role_migration_completed',
        'show_upgrade_success_message',
        'cron_key',
        'csrf_token',
        'custom_download_uri',
        'thumbnails_folder',
        'logo_thumbnails_folder',
        'section',
        'submit',
        'updated',
    ];

    /**
     * @param  array<string, string|null>  $options
     * @return array{
     *     settings: array<string, mixed>,
     *     unmapped: array<string, string>
     * }
     */
    public static function convert(array $options): array
    {
        $settings = [];
        $unmapped = [];

        foreach ($options as $name => $value) {
            if (isset(self::MAP[$name])) {
                [$key, $coercion] = self::MAP[$name];
                $converted = self::coerce($coercion, $value);

                if ($converted !== null) {
                    $settings[$key] = $converted;
                } else {
                    $unmapped[$name] = 'value not recognised, left at the v2 default';
                }

                continue;
            }

            if ($name === 'clients_auto_group') {
                // Resolved by the groups phase; see deferredAutoGroup().
                continue;
            }

            if (str_starts_with($name, 'version_')) {
                $unmapped[$name] = 'install state';

                continue;
            }

            if (in_array($name, self::INSTALL_STATE, true)) {
                $unmapped[$name] = 'install state';

                continue;
            }

            $moved = null;
            foreach (self::ELSEWHERE as $prefix => $destination) {
                if (str_starts_with($name, $prefix)) {
                    $moved = $destination;

                    break;
                }
            }

            $unmapped[$name] = $moved !== null
                ? 'carried by '.$moved
                : 'no equivalent in v2';
        }

        return ['settings' => $settings, 'unmapped' => $unmapped];
    }

    /**
     * The v1 group id new clients are added to, if v1 had one set. Null
     * when unset or zero, which is v1's "no group".
     *
     * @param  array<string, string|null>  $options
     */
    public static function deferredAutoGroup(array $options): ?int
    {
        $value = (int) ($options['clients_auto_group'] ?? 0);

        return $value > 0 ? $value : null;
    }

    private static function coerce(string $coercion, ?string $value): mixed
    {
        $value ??= '';

        return match ($coercion) {
            'string' => LegacyText::line($value) ?? '',
            'int' => (int) $value,
            'bool' => self::toBool($value),
            'inverted_bool' => ! self::toBool($value),

            // v1 stores a comma-separated list with no leading dots.
            // v2 wants a list of bare extensions, deduplicated and
            // lowercased — the same shape its settings screen writes.
            'extensions' => array_values(array_unique(array_filter(array_map(
                static fn (string $extension): string => strtolower(trim($extension, " \t\n\r\0\x0B.")),
                explode(',', $value),
            )))),

            'email_list' => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? [] : [$value],

            'select_group' => in_array($value, ['none', 'public'], true) ? $value : null,
            'upload_restriction' => in_array($value, ['none', 'clients', 'all'], true) ? $value : null,
            'ip_logging' => in_array($value, ['all', 'anonymous_only', 'none'], true) ? $value : null,

            // v1 splits this across two flags (required, plus which
            // methods are allowed); v2 asks only who must use it. A v1
            // install that required 2FA required it of everyone.
            'two_factor' => self::toBool($value) ? 'all' : 'none',

            // Only exact matches. v1's `pinboxes` and any custom
            // template have no v2 counterpart, and picking the
            // closest-looking theme for someone is a guess about their
            // brand, not a migration.
            'theme' => in_array($value, ['default', 'gallery'], true) ? $value : null,

            default => null,
        };
    }

    private static function toBool(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
