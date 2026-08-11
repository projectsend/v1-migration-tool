<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Transform;

/**
 * v1's CAPTCHA configuration into v2's.
 *
 * v1 kept eight flat option rows: one naming the active service and a
 * site/secret pair for each of the three it supported, plus reCAPTCHA
 * v3's score threshold. v2 keeps the credentials in their own table, one
 * row per provider, and names the active one in a setting. The shapes
 * differ; the vocabulary is nearly identical.
 *
 * Two rules, and the second is the one worth arguing about.
 *
 * **Every key pair is carried, not only the active one.** v1 let an
 * administrator configure Turnstile, try reCAPTCHA, and switch back
 * without losing either — v2 keeps keys per provider for exactly the same
 * reason. Dropping the inactive pairs would quietly make that switch cost
 * a trip to two vendor consoles.
 *
 * **A provider whose keys are incomplete does not become the active
 * one.** v2 already treats a half-configured provider as switched off, so
 * writing it through would produce an installation whose settings screen
 * says "Turnstile" while nothing is actually checked. The keys are still
 * carried and the report names what is missing, so the administrator
 * finishes a form rather than discovering a gap.
 */
final class CaptchaMap
{
    /**
     * v1's `captcha_method` values to v2's provider keys. v1's "off" is
     * the string `0`; anything unrecognised is treated the same way,
     * because `captcha_method` is an unconstrained option column and a
     * hand-edited row is a real thing.
     *
     * @var array<string, string>
     */
    private const PROVIDERS = [
        'recaptchav2' => 'recaptcha_v2',
        'recaptchav3' => 'recaptcha_v3',
        'cloudflare_turnstile' => 'turnstile',
    ];

    /**
     * v2 provider key => the v1 option names holding its credentials.
     *
     * @var array<string, array{site: string, secret: string}>
     */
    private const KEYS = [
        'recaptcha_v2' => ['site' => 'recaptcha_site_key', 'secret' => 'recaptcha_secret_key'],
        'recaptcha_v3' => ['site' => 'recaptcha_v3_site_key', 'secret' => 'recaptcha_v3_secret_key'],
        'turnstile' => ['site' => 'cloudflare_turnstile_site_key', 'secret' => 'cloudflare_turnstile_secret_key'],
    ];

    /**
     * Every v1 option this class consumes, so OptionMap can report them
     * as carried rather than as having no equivalent.
     *
     * @return list<string>
     */
    public static function optionNames(): array
    {
        $names = ['captcha_method', 'recaptcha_v3_score_threshold', 'recaptcha_enabled'];

        foreach (self::KEYS as $pair) {
            $names[] = $pair['site'];
            $names[] = $pair['secret'];
        }

        return $names;
    }

    /**
     * @param  array<string, string|null>  $options
     * @return array{
     *     providers: array<string, array{site_key: string|null, secret_key: string|null, score_threshold: float|null}>,
     *     active: string|null,
     *     notes: list<string>
     * }
     */
    public static function convert(array $options): array
    {
        $providers = [];
        $notes = [];

        foreach (self::KEYS as $provider => $pair) {
            $site = self::text($options[$pair['site']] ?? null);
            $secret = self::text($options[$pair['secret']] ?? null);

            if ($site === null && $secret === null) {
                continue;
            }

            $providers[$provider] = [
                'site_key' => $site,
                'secret_key' => $secret,
                'score_threshold' => $provider === 'recaptcha_v3'
                    ? self::threshold($options['recaptcha_v3_score_threshold'] ?? null)
                    : null,
            ];
        }

        $selected = self::PROVIDERS[trim((string) ($options['captcha_method'] ?? ''))] ?? null;

        if ($selected === null) {
            return ['providers' => $providers, 'active' => null, 'notes' => $notes];
        }

        $keys = $providers[$selected] ?? null;

        if ($keys === null || $keys['site_key'] === null || $keys['secret_key'] === null) {
            $notes[] = sprintf(
                'v1 had %s selected but its keys are incomplete, so CAPTCHA arrives switched off. '
                .'Whatever keys were stored have been carried; finish them at Settings → CAPTCHA to switch it on.',
                $selected,
            );

            return ['providers' => $providers, 'active' => null, 'notes' => $notes];
        }

        return ['providers' => $providers, 'active' => $selected, 'notes' => $notes];
    }

    /**
     * v1's default is the string '0.5'. Anything outside reCAPTCHA's own
     * 0–1 range is a hand-edited row rather than a decision, and a
     * threshold above 1 would refuse every human on the site.
     */
    private static function threshold(?string $value): float
    {
        if ($value === null || ! is_numeric(trim($value))) {
            return 0.5;
        }

        $threshold = (float) trim($value);

        return $threshold >= 0.0 && $threshold <= 1.0 ? $threshold : 0.5;
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
