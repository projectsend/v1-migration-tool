<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;

/**
 * v1's SMTP configuration.
 *
 * Worth carrying because an install that cannot send email cannot tell
 * anyone their account moved — and re-entering an SMTP password is
 * exactly the step an administrator postpones.
 *
 * **The password is encrypted before it is written, and that is not
 * optional.** The host casts `mail_provider_settings.password` as
 * `encrypted`, so a plaintext value in that column does not read back as
 * plaintext — it throws a decryption failure the first time the mailer
 * is configured, which surfaces as "email is broken" with nothing in the
 * logs pointing at this. Writing the table directly means matching the
 * cast by hand: `Crypt::encryptString()` is the same call the cast makes
 * for a string.
 *
 * Nothing is written when v1 was not using SMTP. v1's `mail_system_use`
 * distinguishes PHP's `mail()` from SMTP, and importing a half-filled
 * SMTP row for an install that used `mail()` would leave v2 configured
 * for a server that was never there.
 */
final class MailSettingsPhase implements Phase
{
    public function key(): string
    {
        return 'mail_settings';
    }

    public function label(): string
    {
        return 'Email settings';
    }

    public function total(MigrationContext $context): int
    {
        return 1;
    }

    public function chunk(MigrationContext $context, int $cursor): ?int
    {
        if ($cursor > 0) {
            return null;
        }

        $options = $context->source->options();
        $host = trim((string) ($options['mail_smtp_host'] ?? ''));

        if ($host === '' || ($options['mail_system_use'] ?? 'smtp') === 'php') {
            $context->count($this->key(), 'skipped_not_using_smtp');

            return 1;
        }

        $password = (string) ($options['mail_smtp_pass'] ?? '');

        DB::table(HostTables::MAIL_PROVIDER_SETTINGS)->updateOrInsert(
            ['id' => 1],
            [
                'provider' => 'custom',
                'host' => $host,
                'port' => (int) ($options['mail_smtp_port'] ?? 587),
                'username' => (string) ($options['mail_smtp_user'] ?? '') ?: null,
                'password' => $password !== '' ? Crypt::encryptString($password) : null,
                'encryption' => $this->encryption((string) ($options['mail_smtp_secure'] ?? 'tls')),
                'from_address' => (string) ($options['admin_email_address'] ?? '') ?: null,
                'from_name' => (string) ($options['mail_from_name'] ?? '') ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $context->count($this->key(), 'imported');

        return 1;
    }

    /**
     * v1 offers ssl, tls or nothing; v2's column is a string with the
     * same vocabulary, so this only normalises the empty case.
     */
    private function encryption(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['ssl', 'tls'], true) ? $value : 'tls';
    }
}
