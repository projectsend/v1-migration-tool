<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Host;

/**
 * The host schema this package writes into, declared once.
 *
 * This package is built and tested with no host application present, so
 * it cannot reference App\Models\User, App\Modules\Files\Models\File or
 * any other host class at build time — bulk writes go through the query
 * builder against table *names*, which is what the volume work wants
 * anyway (no model events, no per-row slug query).
 *
 * The cost of that is a contract nothing enforces at compile time: the
 * host can rename a column in a release and this package would discover
 * it halfway through writing 200,000 rows. So the contract is written
 * down here and checked before a run starts — see HostSchemaCheck. Every
 * table and column the importer writes must appear below; adding a write
 * without adding it here means the preflight will pass and the run will
 * fail.
 *
 * Column lists are deliberately only what this package *writes*. Host
 * columns it leaves at their database default (files.commentable,
 * users.dashboard_columns, and so on) are not listed and not required.
 */
final class HostTables
{
    public const USERS = 'users';

    public const ROLES = 'roles';

    public const ROLE_PERMISSION = 'role_permission';

    public const STAFF_CLIENT_ASSIGNMENTS = 'staff_client_assignments';

    public const GROUPS = 'groups';

    public const GROUP_MEMBERS = 'group_members';

    public const MEMBERSHIP_REQUESTS = 'membership_requests';

    public const CATEGORIES = 'categories';

    public const CATEGORY_FILE = 'category_file';

    public const FOLDERS = 'folders';

    public const FOLDER_ASSIGNMENTS = 'folder_assignments';

    public const FILES = 'files';

    public const FILE_ASSIGNMENTS = 'file_assignments';

    public const SHARE_LINKS = 'share_links';

    public const CLIENT_CUSTOM_FIELDS = 'client_custom_fields';

    public const CLIENT_CUSTOM_FIELD_VALUES = 'client_custom_field_values';

    public const ACTIVITY_LOG = 'activity_log';

    public const SETTINGS = 'settings';

    public const MAIL_PROVIDER_SETTINGS = 'mail_provider_settings';

    public const LDAP_SETTINGS = 'ldap_settings';

    public const EMAIL_TEMPLATES = 'email_templates';

    public const NOTIFICATION_PREFERENCES = 'notification_preferences';

    /**
     * Morph type strings written into the two polymorphic assignment
     * tables. The host registers no morph map (verified: no
     * enforceMorphMap/morphMap call anywhere in it), so these columns
     * hold fully-qualified class names. They are string literals here
     * precisely because this package must not import those classes.
     */
    public const MORPH_USER = 'App\Models\User';

    public const MORPH_GROUP = 'App\Modules\Groups\Models\Group';

    /**
     * Table => the columns this package writes to it.
     *
     * @return array<string, list<string>>
     */
    public static function writes(): array
    {
        return [
            self::USERS => [
                'id', 'type', 'role_id', 'active', 'account_requested', 'name',
                'email', 'password', 'locale', 'storage_quota_mb',
                'created_at', 'updated_at',
            ],
            self::ROLES => [
                'id', 'name', 'is_system', 'is_administrator', 'client_scoped',
                'created_at', 'updated_at',
            ],
            self::ROLE_PERMISSION => ['role_id', 'permission'],
            self::STAFF_CLIENT_ASSIGNMENTS => [
                'staff_id', 'client_id', 'created_at', 'updated_at',
            ],
            self::GROUPS => [
                'id', 'name', 'slug', 'description', 'public',
                'created_at', 'updated_at',
            ],
            self::GROUP_MEMBERS => ['group_id', 'user_id', 'created_at', 'updated_at'],
            self::MEMBERSHIP_REQUESTS => [
                'group_id', 'user_id', 'status', 'denied_at',
                'created_at', 'updated_at',
            ],
            self::CATEGORIES => ['id', 'name', 'color', 'created_at', 'updated_at'],
            self::CATEGORY_FILE => [
                'category_id', 'file_id', 'created_at', 'updated_at',
            ],
            self::FOLDERS => [
                'id', 'name', 'parent_id', 'created_by', 'path', 'public', 'slug',
                'allow_client_uploads', 'created_at', 'updated_at',
            ],
            self::FOLDER_ASSIGNMENTS => [
                'folder_id', 'assignable_type', 'assignable_id',
                'created_at', 'updated_at',
            ],
            self::FILES => [
                'id', 'uploaded_by', 'folder_id', 'name', 'description',
                'original_name', 'path', 'disk', 'mime_type', 'size', 'checksum',
                'public', 'slug', 'expires_at', 'created_at', 'updated_at',
            ],
            self::FILE_ASSIGNMENTS => [
                'file_id', 'assignable_type', 'assignable_id',
                'created_at', 'updated_at',
            ],
            self::SHARE_LINKS => [
                'shareable_type', 'shareable_id', 'token', 'created_by',
                'expires_at', 'max_downloads', 'downloads_count',
                'created_at', 'updated_at',
            ],
            self::CLIENT_CUSTOM_FIELDS => [
                'name', 'label', 'type', 'options', 'required',
                'client_editability', 'client_contexts', 'sort_order',
                'created_at', 'updated_at',
            ],
            self::CLIENT_CUSTOM_FIELD_VALUES => [
                'client_custom_field_id', 'user_id', 'value',
                'created_at', 'updated_at',
            ],
            self::ACTIVITY_LOG => [
                'actor_id', 'actor_name', 'actor_type', 'origin', 'ip_address',
                'action', 'subject_type', 'subject_id', 'subject_name', 'context',
                'created_at',
            ],
            self::SETTINGS => ['key', 'value', 'created_at', 'updated_at'],
            self::MAIL_PROVIDER_SETTINGS => [
                'provider', 'host', 'port', 'username', 'password', 'encryption',
                'from_address', 'from_name',
            ],
            self::LDAP_SETTINGS => [
                'active', 'host', 'port', 'encryption', 'bind_dn', 'bind_password',
                'base_dn', 'user_filter', 'email_attribute', 'name_attribute',
                'auto_provision', 'auto_approve',
            ],
            self::EMAIL_TEMPLATES => ['slot', 'subject', 'body'],
            self::NOTIFICATION_PREFERENCES => [
                'user_id', 'type', 'email_enabled', 'created_at', 'updated_at',
            ],
        ];
    }

    /**
     * Tables that must be empty (beyond the setup administrator) for a
     * run to be allowed to start. The tool imports into a fresh install
     * only; it has no merge semantics and no conflict policy, so finding
     * existing content means someone is about to lose data.
     *
     * @return list<string>
     */
    public static function mustBeEmpty(): array
    {
        return [
            self::FILES,
            self::GROUPS,
            self::FOLDERS,
            self::CATEGORIES,
            self::FILE_ASSIGNMENTS,
        ];
    }
}
