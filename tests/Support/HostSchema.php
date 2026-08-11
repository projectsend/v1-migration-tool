<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A replica of the ProjectSend v2 tables this package writes into.
 *
 * The package is tested against a throwaway Testbench app with no host
 * present, so the tables have to come from somewhere. Each block below
 * is transcribed from the host migration named above it, and carries the
 * constraints that actually catch bugs — the unique slug columns, the
 * unique email, the not-null checksum — because those are exactly what
 * an importer gets wrong.
 *
 * This is a replica, not the truth. The truth is checked at runtime by
 * HostSchemaCheck against the real database, which is why a drift here
 * degrades the tests rather than silently corrupting an install.
 */
final class HostSchema
{
    public static function create(): void
    {
        // 0001_01_01_000000_create_users_table.php, plus add_type,
        // add_role_id, add_active, add_account_requested, add_locale,
        // add_storage_quota_mb, soft deletes.
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->default('staff')->index();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('account_requested')->default(false);
            $table->string('name');
            $table->string('email')->unique();
            $table->string('locale')->nullable();
            $table->unsignedInteger('storage_quota_mb')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2026_07_24_090000_create_roles_tables.php + add_client_scoped.
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_administrator')->default(false);
            $table->boolean('client_scoped')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->string('permission');
            $table->unique(['role_id', 'permission']);
        });

        // 2026_07_28_130000_create_staff_client_assignments_table.php
        Schema::create('staff_client_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('client_id');
            $table->timestamps();
            $table->unique(['staff_id', 'client_id'], 'staff_client_unique');
        });

        // 2026_07_26_090000_create_groups_tables.php + add_slug +
        // add_status_to_membership_requests.
        Schema::create('groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('public')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('group_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['group_id', 'user_id']);
        });

        Schema::create('membership_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('pending')->index();
            $table->timestamp('denied_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'user_id']);
        });

        // 2026_07_28_140000_create_categories_tables.php + add_color.
        // Note: flat by design in v2 — there is no parent_id.
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('color')->default('gray');
            $table->timestamps();
        });

        Schema::create('category_file', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('file_id');
            $table->timestamps();
            $table->unique(['category_id', 'file_id']);
        });

        // 2026_07_28_090000_create_folders_tables.php + add_public.
        Schema::create('folders', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->boolean('public')->default(false);
            $table->string('slug')->unique();
            $table->boolean('allow_client_uploads')->default(false);
            $table->string('path')->default('/')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('folder_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('folder_id');
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->timestamps();
            $table->unique(
                ['folder_id', 'assignable_type', 'assignable_id'],
                'folder_assignment_unique',
            );
        });

        // 2026_07_27_090000_create_files_tables.php + folder_id, public,
        // slug, disk, expires_at, commentable, versioning, download limits.
        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->unsignedBigInteger('previous_file_id')->nullable();
            $table->unsignedBigInteger('version_root_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('public')->default(false);
            $table->boolean('commentable')->default(false);
            $table->string('slug')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('download_limit')->nullable();
            $table->string('download_limit_scope', 16)->default('total');
            $table->string('original_name');
            $table->string('path');
            $table->string('disk')->default('files');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('file_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('file_id');
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->timestamps();
            $table->unique(['file_id', 'assignable_type', 'assignable_id']);
        });

        // 2026_07_30_090000_create_share_links_table.php
        Schema::create('share_links', function (Blueprint $table): void {
            $table->id();
            $table->string('shareable_type');
            $table->unsignedBigInteger('shareable_id');
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('downloads_count')->default(0);
            $table->timestamps();
        });

        // 2026_08_05_090000_create_client_custom_fields_tables.php
        Schema::create('client_custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->string('type');
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->string('client_editability')->default('hidden');
            $table->json('client_contexts')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('client_custom_field_values', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('client_custom_field_id');
            $table->unsignedBigInteger('user_id');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['client_custom_field_id', 'user_id'], 'client_custom_field_value_unique');
        });

        // 2026_07_23_120000_create_activity_log_table.php + actor_type,
        // ip_address, origin. No updated_at: rows are immutable.
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_type')->nullable()->index();
            $table->string('origin', 16)->default('ui')->index();
            $table->unsignedBigInteger('api_token_id')->nullable();
            $table->string('api_token_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_name')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->index();
        });

        // 2026_07_22_210000_create_settings_table.php
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // 2026_08_02_090000_create_mail_provider_settings_table.php
        Schema::create('mail_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('custom');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('encryption')->default('tls');
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->timestamps();
        });

        // 2026_09_01_090000_create_captcha_providers_table.php
        Schema::create('captcha_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->unique();
            $table->string('site_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->decimal('score_threshold', 3, 2)->nullable()->default(0.5);
            $table->timestamps();
        });

        // 2026_08_27_090100_create_ldap_settings_table.php + auto_approve.
        Schema::create('ldap_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active')->default(false);
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(389);
            $table->string('encryption')->default('tls');
            $table->string('ca_cert_path')->nullable();
            $table->string('bind_dn')->nullable();
            $table->text('bind_password')->nullable();
            $table->string('base_dn')->nullable();
            $table->string('user_filter')->nullable();
            $table->string('email_attribute')->default('mail');
            $table->string('name_attribute')->default('cn');
            $table->boolean('auto_provision')->default(false);
            $table->boolean('auto_approve')->default(false);
            $table->timestamps();
        });

        // 2026_08_03_090000_create_email_templates_table.php
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('slot')->unique();
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
        });

        // 2026_08_10_090100_create_notification_preferences_table.php
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->boolean('email_enabled');
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });
    }
}
