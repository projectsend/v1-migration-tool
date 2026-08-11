<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Transform\CaptchaMap;

/**
 * v1's CAPTCHA keys and whichever service was switched on.
 *
 * Worth carrying for the same reason as the SMTP password: re-entering a
 * credential from a vendor console in another browser tab is exactly the
 * step an administrator postpones, and an unprotected registration form
 * is not a good thing to postpone.
 *
 * **The secret is encrypted before it is written, and that is not
 * optional.** The host casts `captcha_providers.secret_key` as
 * `encrypted`. Plaintext in that column does not read back as plaintext;
 * it throws a decryption failure the first time somebody signs in, which
 * — because a failure to verify deliberately fails *open* — surfaces as
 * "the CAPTCHA silently stopped working" rather than as an error anybody
 * would connect to this. `Crypt::encryptString()` is the same call the
 * cast makes. v1 stored all six of these in plain text.
 *
 * **The key source is set to `own` whenever a provider is switched on.**
 * The host's default is `managed`, meaning "use the platform's keys",
 * which is inert on a self-hosted install but authoritative on the cloud
 * one. Left alone, an import into a cloud tenant would carry the keys
 * across and then ignore them. It is only written when there is something
 * to point at: an install that had no CAPTCHA should keep the platform's
 * protection rather than be switched to nothing it owns.
 *
 * The per-form switches are left at v2's defaults, which is all four on.
 * v1's CAPTCHA covered login, registration and password reset — the three
 * forms the two versions share — so the shared behaviour matches. The
 * fourth is comments from visitors, a v2 feature v1 had no opinion about.
 */
final class CaptchaSettingsPhase implements Phase
{
    private const SETTINGS_CACHE_KEY = 'platform.settings';

    private const DISPLAY_CACHE_KEY = 'platform.captcha.display.v1';

    public function key(): string
    {
        return 'captcha_settings';
    }

    public function label(): string
    {
        return 'CAPTCHA settings';
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

        // The one optional table this package writes — see
        // HostTables::optional(). A host from before v2 grew a CAPTCHA is
        // still a perfectly good destination for everything else, and
        // refusing an entire migration over a settings screen would be
        // wildly out of proportion.
        if (! Schema::hasTable(HostTables::CAPTCHA_PROVIDERS)) {
            $context->count($this->key(), 'skipped_host_has_no_captcha');

            return 1;
        }

        $converted = CaptchaMap::convert($context->source->options());

        if ($converted['providers'] === []) {
            $context->count($this->key(), 'skipped_none_configured');

            return 1;
        }

        $now = now();

        foreach ($converted['providers'] as $provider => $keys) {
            DB::table(HostTables::CAPTCHA_PROVIDERS)->updateOrInsert(
                ['provider' => $provider],
                [
                    'site_key' => $keys['site_key'],
                    'secret_key' => $keys['secret_key'] !== null
                        ? Crypt::encryptString($keys['secret_key'])
                        : null,
                    'score_threshold' => $keys['score_threshold'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $context->count($this->key(), 'keys_imported');
        }

        if ($converted['active'] !== null) {
            $this->setting('captcha_provider', $converted['active'], $now);
            $this->setting('captcha_key_source', 'own', $now);

            $context->count($this->key(), 'switched_on');
        }

        if ($converted['notes'] !== []) {
            $context->set($this->key(), 'notes', $converted['notes']);
        }

        // Both of the host's caches this touches are rememberForever, so
        // without these an import that ran through the queue leaves the
        // web process serving pre-migration values indefinitely.
        Cache::forget(self::SETTINGS_CACHE_KEY);
        Cache::forget(self::DISPLAY_CACHE_KEY);

        return 1;
    }

    /**
     * JSON, because the host's `settings.value` is a JSON column — the
     * same reason SettingsPhase encodes.
     */
    private function setting(string $key, string $value, mixed $now): void
    {
        DB::table(HostTables::SETTINGS)->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
}
