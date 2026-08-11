<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\IdMap;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Phases\CaptchaSettingsPhase;
use ProjectSend\V1Migration\Source\BundleSource;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Tests\Support\BundleBuilder;

/**
 * v1's CAPTCHA configuration, which v2 gained a home for.
 *
 * None of the three ps-seed fixtures configures a CAPTCHA — the upgrade
 * that adds the options leaves them empty — so unlike most of this
 * package, nothing here is covered by running against a real install.
 * These are the coverage.
 */
function importCaptcha(array $options): MigrationContext
{
    $rows = [];
    foreach (array_values($options) as $index => $value) {
        $rows[] = ['id' => $index + 1, 'name' => array_keys($options)[$index], 'value' => $value];
    }

    $path = (new BundleBuilder)->table(V1Tables::OPTIONS, $rows)->write();

    $run = MigrationRun::create([
        'status' => MigrationRun::STATUS_RUNNING,
        'mode' => MigrationRun::MODE_BUNDLE,
        'source' => ['bundle_path' => $path],
        'options' => [],
    ]);

    $context = new MigrationContext($run, new BundleSource($path), new IdMap($run->id));

    (new CaptchaSettingsPhase)->chunk($context, 0);
    $context->flushNotes();

    return $context;
}

function storedProvider(string $provider): ?object
{
    return DB::table(HostTables::CAPTCHA_PROVIDERS)->where('provider', $provider)->first();
}

function storedSetting(string $key): ?string
{
    $value = DB::table(HostTables::SETTINGS)->where('key', $key)->value('value');

    return $value === null ? null : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
}

it('carries the active provider and switches it on', function (): void {
    importCaptcha([
        'captcha_method' => 'cloudflare_turnstile',
        'cloudflare_turnstile_site_key' => '0x4AAA-site',
        'cloudflare_turnstile_secret_key' => '0x4AAA-secret',
    ]);

    $row = storedProvider('turnstile');

    expect($row->site_key)->toBe('0x4AAA-site')
        ->and(storedSetting('captcha_provider'))->toBe('turnstile');
});

// The host casts this column as `encrypted`. Plaintext there does not
// read back as plaintext — it throws on decrypt, and because a failure to
// verify deliberately fails open, that surfaces as the CAPTCHA quietly
// not working rather than as an error anybody would trace back to here.
it('encrypts the secret, the way the host reads it', function (): void {
    importCaptcha([
        'captcha_method' => 'cloudflare_turnstile',
        'cloudflare_turnstile_site_key' => 'site',
        'cloudflare_turnstile_secret_key' => 'the-secret',
    ]);

    $stored = storedProvider('turnstile')->secret_key;

    expect($stored)->not->toBe('the-secret')
        ->and(Crypt::decryptString($stored))->toBe('the-secret');
});

// v2's default key source is "use the platform's keys", which is inert
// self-hosted but authoritative on cloud. Left alone, an import into a
// cloud tenant would carry these keys across and then ignore them.
it('points the installation at its own keys once one is switched on', function (): void {
    importCaptcha([
        'captcha_method' => 'recaptchav2',
        'recaptcha_site_key' => 'site',
        'recaptcha_secret_key' => 'secret',
    ]);

    expect(storedSetting('captcha_key_source'))->toBe('own');
});

// An install with no CAPTCHA should keep whatever protection its
// destination provides, rather than being switched to nothing it owns.
it('leaves the key source alone when v1 had no captcha', function (): void {
    importCaptcha(['captcha_method' => '0']);

    expect(storedSetting('captcha_key_source'))->toBeNull()
        ->and(storedSetting('captcha_provider'))->toBeNull();
});

it('carries every key pair, not only the active one', function (): void {
    importCaptcha([
        'captcha_method' => 'recaptchav2',
        'recaptcha_site_key' => 'v2-site',
        'recaptcha_secret_key' => 'v2-secret',
        'cloudflare_turnstile_site_key' => 'turnstile-site',
        'cloudflare_turnstile_secret_key' => 'turnstile-secret',
    ]);

    // Switching back to Turnstile must not cost a trip to Cloudflare's
    // console — the same reason v2 keeps keys per provider.
    expect(storedProvider('turnstile')->site_key)->toBe('turnstile-site')
        ->and(storedSetting('captcha_provider'))->toBe('recaptcha_v2');
});

it('carries reCAPTCHA v3 with its threshold', function (): void {
    importCaptcha([
        'captcha_method' => 'recaptchav3',
        'recaptcha_v3_site_key' => 'site',
        'recaptcha_v3_secret_key' => 'secret',
        'recaptcha_v3_score_threshold' => '0.7',
    ]);

    expect((float) storedProvider('recaptcha_v3')->score_threshold)->toBe(0.7);
});

it('falls back to the usual threshold when v1 holds a nonsense one', function (string $value): void {
    importCaptcha([
        'captcha_method' => 'recaptchav3',
        'recaptcha_v3_site_key' => 'site',
        'recaptcha_v3_secret_key' => 'secret',
        'recaptcha_v3_score_threshold' => $value,
    ]);

    // A threshold above 1 refuses every human on the site, and the option
    // column is unconstrained text, so a hand-edited row is a real thing.
    expect((float) storedProvider('recaptcha_v3')->score_threshold)->toBe(0.5);
})->with(['1.5', '-1', 'high', '']);

// v2 treats a half-configured provider as switched off, so writing this
// one through would produce an installation whose settings screen names a
// service while nothing is actually being checked.
it('does not switch on a provider whose keys are incomplete', function (): void {
    $context = importCaptcha([
        'captcha_method' => 'cloudflare_turnstile',
        'cloudflare_turnstile_site_key' => 'site-only',
    ]);

    expect(storedSetting('captcha_provider'))->toBeNull()
        // The half a pair v1 had is still carried, so finishing it is a
        // form to complete rather than a gap to discover.
        ->and(storedProvider('turnstile')->site_key)->toBe('site-only')
        ->and($context->run->fresh()->report['captcha_settings']['notes'] ?? [])
        ->toHaveCount(1);
});

it('treats an unrecognised method as no captcha at all', function (): void {
    importCaptcha([
        'captcha_method' => 'hcaptcha',
        'recaptcha_site_key' => 'site',
        'recaptcha_secret_key' => 'secret',
    ]);

    expect(storedSetting('captcha_provider'))->toBeNull();
});

// A v2 from before the CAPTCHA feature is still a perfectly good
// destination for every account, file and share. Refusing the whole
// migration over a settings screen would trade something that matters for
// something that does not.
it('skips cleanly when the host is older than the feature', function (): void {
    Schema::drop(HostTables::CAPTCHA_PROVIDERS);

    $context = importCaptcha([
        'captcha_method' => 'cloudflare_turnstile',
        'cloudflare_turnstile_site_key' => 'site',
        'cloudflare_turnstile_secret_key' => 'secret',
    ]);

    expect($context->run->fresh()->report['captcha_settings']['skipped_host_has_no_captcha'] ?? 0)->toBe(1)
        ->and(storedSetting('captcha_provider'))->toBeNull();
});

// Found by running against a real host that predated the feature: the
// pre-run baseline reads max(id) from every table in the contract, so it
// hit the missing one long before the phase's own guard could skip it.
// The whole run died at the first write.
it('takes a baseline without the optional table the host has not got', function (): void {
    Schema::drop(HostTables::CAPTCHA_PROVIDERS);

    $baseline = ProjectSend\V1Migration\Host\Baseline::capture();

    expect($baseline)->not->toHaveKey(HostTables::CAPTCHA_PROVIDERS)
        ->and($baseline)->toHaveKey(HostTables::USERS);
});
