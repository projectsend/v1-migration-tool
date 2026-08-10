<?php

declare(strict_types=1);

use ProjectSend\V1Migration\Transform\OptionMap;

it('carries the options v2 still has, with the right PHP types', function (): void {
    $result = OptionMap::convert([
        'this_install_title' => 'Northwind Studio — client files',
        'clients_can_register' => '1',
        'clients_auto_approve' => '0',
        'clients_default_disk_quota' => '500',
    ]);

    expect($result['settings'])->toBe([
        'site_name' => 'Northwind Studio — client files',
        'clients_can_register' => true,
        'clients_auto_approve' => false,
        'default_client_storage_quota_mb' => 500,
    ]);
});

it('treats v1 disk quotas as megabytes, not bytes', function (): void {
    // The column is `bigint unsigned`, which reads like bytes and is
    // not: v1 converts with `$max_disk_quota * 1048576` at the point of
    // use. Dividing here would turn every quota into unlimited.
    $result = OptionMap::convert(['clients_default_disk_quota' => '2048']);

    expect($result['settings']['default_client_storage_quota_mb'])->toBe(2048);
});

it('inverts the update check, because v1 asks the opposite question', function (): void {
    expect(OptionMap::convert(['prevent_updates_check' => '1'])['settings'])
        ->toBe(['check_for_updates' => false])
        ->and(OptionMap::convert(['prevent_updates_check' => '0'])['settings'])
        ->toBe(['check_for_updates' => true]);
});

it('turns the allowed file types list into bare lowercase extensions', function (): void {
    $result = OptionMap::convert([
        'allowed_file_types' => 'JPG, .png ,pdf,zip,jpg,',
    ]);

    expect($result['settings']['allowed_upload_extensions'])
        ->toBe(['jpg', 'png', 'pdf', 'zip']);
});

it('validates shared vocabularies rather than trusting them', function (): void {
    expect(OptionMap::convert(['file_types_limit_to' => 'clients'])['settings'])
        ->toBe(['upload_type_restriction' => 'clients']);

    // A hand-edited options row is a real thing; an unrecognised value
    // leaves v2 at its own default and says so.
    $garbage = OptionMap::convert(['file_types_limit_to' => 'sometimes']);

    expect($garbage['settings'])->toBe([])
        ->and($garbage['unmapped']['file_types_limit_to'])->toContain('not recognised');
});

it('collapses v1s two 2FA flags into v2s single enforcement setting', function (): void {
    expect(OptionMap::convert(['two_factor_required' => '1'])['settings'])
        ->toBe(['two_factor_enforcement' => 'all'])
        ->and(OptionMap::convert(['two_factor_required' => '0'])['settings'])
        ->toBe(['two_factor_enforcement' => 'none']);
});

it('carries a theme only when v2 has one by the same name', function (): void {
    expect(OptionMap::convert(['selected_clients_template' => 'gallery'])['settings'])
        ->toBe(['theme' => 'gallery']);

    // pinboxes, retro90s, anything custom: picking the closest-looking
    // v2 theme would be a guess about someone's brand.
    $unknown = OptionMap::convert(['selected_clients_template' => 'pinboxes']);

    expect($unknown['settings'])->toBe([])
        ->and($unknown['unmapped'])->toHaveKey('selected_clients_template');
});

it('never copies install state', function (): void {
    $result = OptionMap::convert([
        'base_uri' => 'https://old.example.com/',
        'database_version' => '2026080702',
        'version_new_number' => 'r2098',
        'cron_key' => 'secret',
    ]);

    expect($result['settings'])->toBe([])
        ->and($result['unmapped'])->toBe([
            'base_uri' => 'install state',
            'database_version' => 'install state',
            'version_new_number' => 'install state',
            'cron_key' => 'install state',
        ]);
});

it('says where options that moved elsewhere went', function (): void {
    $result = OptionMap::convert([
        'mail_smtp_host' => 'smtp.example.com',
        'google_client_id' => 'abc',
        'email_new_file_by_user_subject' => 'A new file',
        'ldap_admin_password' => 'hunter2',
    ]);

    expect($result['unmapped']['mail_smtp_host'])->toContain('Email')
        ->and($result['unmapped']['google_client_id'])->toContain('Social login')
        ->and($result['unmapped']['email_new_file_by_user_subject'])->toContain('templates')
        ->and($result['unmapped']['ldap_admin_password'])->toContain('LDAP');
});

it('tolerates junk rows instead of failing on them', function (): void {
    // Observed in the wild: a settings form post writing its own field
    // names straight into the options table. v1 has no unique key on
    // `name` either.
    $result = OptionMap::convert([
        'section' => 'clients',
        'submit' => 'Save',
        'something_a_fork_invented' => '1',
    ]);

    expect($result['settings'])->toBe([])
        ->and($result['unmapped']['section'])->toBe('install state')
        ->and($result['unmapped']['something_a_fork_invented'])->toBe('no equivalent in v2');
});

it('defers the auto-group option until groups have ids', function (): void {
    expect(OptionMap::deferredAutoGroup(['clients_auto_group' => '7']))->toBe(7)
        ->and(OptionMap::deferredAutoGroup(['clients_auto_group' => '0']))->toBeNull()
        ->and(OptionMap::deferredAutoGroup([]))->toBeNull();

    // and it must not leak into the settings written in phase one
    expect(OptionMap::convert(['clients_auto_group' => '7']))
        ->toBe(['settings' => [], 'unmapped' => []]);
});

it('takes only a valid admin email address', function (): void {
    expect(OptionMap::convert(['admin_email_address' => 'admin@example.com'])['settings'])
        ->toBe(['admin_notification_emails' => ['admin@example.com']])
        ->and(OptionMap::convert(['admin_email_address' => ''])['settings'])
        ->toBe(['admin_notification_emails' => []]);
});
