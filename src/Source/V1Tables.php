<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Source;

/**
 * The v1 tables this tool reads, unprefixed.
 *
 * v1 ships 29 tables; this is the subset that carries anything worth
 * moving. The rest are either transient security state
 * (password_reset, logins_failed, remember_tokens, authentication_codes,
 * totp_backup_codes), derived caches (cron_log), or features v2
 * implements differently enough that a row-level import would be a
 * mistranslation rather than a migration (custom_assets is a separate
 * installable package here; custom_downloads and notifications have no
 * v2 counterpart at all).
 *
 * `integrations` is read but never imported: it is how the preflight
 * knows a v1 install had files on S3, GCS or Azure, which is a blocker
 * rather than something to guess at.
 */
final class V1Tables
{
    public const USERS = 'users';

    public const ROLES = 'roles';

    public const ROLE_PERMISSIONS = 'role_permissions';

    public const GROUPS = 'groups';

    public const MEMBERS = 'members';

    public const MEMBERS_REQUESTS = 'members_requests';

    public const CATEGORIES = 'categories';

    public const CATEGORIES_RELATIONS = 'categories_relations';

    public const FOLDERS = 'folders';

    public const FILES = 'files';

    public const FILES_RELATIONS = 'files_relations';

    public const DOWNLOADS = 'downloads';

    public const ACTIONS_LOG = 'actions_log';

    public const OPTIONS = 'options';

    public const CUSTOM_FIELDS = 'custom_fields';

    public const CUSTOM_FIELD_VALUES = 'custom_field_values';

    public const USER_LIMIT_UPLOAD_TO = 'user_limit_upload_to';

    public const INTEGRATIONS = 'integrations';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::USERS,
            self::ROLES,
            self::ROLE_PERMISSIONS,
            self::GROUPS,
            self::MEMBERS,
            self::MEMBERS_REQUESTS,
            self::CATEGORIES,
            self::CATEGORIES_RELATIONS,
            self::FOLDERS,
            self::FILES,
            self::FILES_RELATIONS,
            self::DOWNLOADS,
            self::ACTIONS_LOG,
            self::OPTIONS,
            self::CUSTOM_FIELDS,
            self::CUSTOM_FIELD_VALUES,
            self::USER_LIMIT_UPLOAD_TO,
            self::INTEGRATIONS,
        ];
    }
}
