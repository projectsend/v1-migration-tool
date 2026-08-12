<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use ProjectSend\V1Migration\Tests\Support\V1Passwords;
use ProjectSend\V1Migration\Transform\LegacyPassword;

it('accepts the bcrypt digest v1 actually writes', function (): void {
    // password_hash($p, PASSWORD_DEFAULT, ['cost' => 8]) on every
    // supported v1 install.
    expect(LegacyPassword::isVerifiable(V1Passwords::bcrypt()))->toBeTrue();
});

it('accepts the other bcrypt prefixes', function (): void {
    // A v1 that ran on a PHP or platform emitting $2a$/$2b$ is still
    // bcrypt, and password_verify() reads all three.
    expect(LegacyPassword::isVerifiable('$2a$08$abcdefghijklmnopqrstuv'))->toBeTrue()
        ->and(LegacyPassword::isVerifiable('$2b$12$abcdefghijklmnopqrstuv'))->toBeTrue();
});

it('does not care what cost the digest carries', function (): void {
    // v2 re-hashes a stale cost on the first successful login, so cost is
    // not a reason to flag an account.
    expect(LegacyPassword::isVerifiable('$2y$04$abcdefghijklmnopqrstuv'))->toBeTrue()
        ->and(LegacyPassword::isVerifiable('$2y$13$abcdefghijklmnopqrstuv'))->toBeTrue();
});

it('rejects a blank password', function (): void {
    // Imports fine, then silently refuses every sign-in.
    expect(LegacyPassword::isVerifiable(''))->toBeFalse();
});

it('rejects a digest from another algorithm', function (): void {
    // These are the dangerous ones: v2 verifies the algorithm, so
    // Hash::check() throws rather than returning false, and the login
    // errors instead of refusing.
    expect(LegacyPassword::isVerifiable(md5('secret')))->toBeFalse()
        ->and(LegacyPassword::isVerifiable(sha1('secret')))->toBeFalse()
        ->and(LegacyPassword::isVerifiable('$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$hash'))->toBeFalse();
});

it('rejects a value that merely looks like a prefix', function (): void {
    expect(LegacyPassword::isVerifiable('$2y$'))->toBeTrue()
        ->and(LegacyPassword::isVerifiable('2y$08$abc'))->toBeFalse()
        ->and(LegacyPassword::isVerifiable('not a hash at all'))->toBeFalse();
});

it('carries a verifiable digest into the import untouched', function (): void {
    // The whole point of the migration: the password someone already has
    // keeps working, so nothing about it is rewritten.
    expect(LegacyPassword::forImport(V1Passwords::bcrypt()))->toBe(V1Passwords::bcrypt());
});

it('replaces one v2 cannot check with a digest nobody holds', function (): void {
    // Valid bcrypt, so the login form refuses it instead of throwing on
    // it — and unguessable, so the account is not left open.
    $replacement = LegacyPassword::forImport(md5('secret'));

    expect(LegacyPassword::isVerifiable($replacement))->toBeTrue()
        ->and(Hash::check('secret', $replacement))->toBeFalse()
        ->and(Hash::check(md5('secret'), $replacement))->toBeFalse();
});

it('replaces a blank password too', function (): void {
    $replacement = LegacyPassword::forImport('');

    expect(LegacyPassword::isVerifiable($replacement))->toBeTrue()
        ->and(Hash::check('', $replacement))->toBeFalse();
});

it('gives each account its own replacement', function (): void {
    // A shared stand-in would be one secret that opens every broken
    // account the moment it leaked from any of them.
    expect(LegacyPassword::forImport(''))->not->toBe(LegacyPassword::forImport(''));
});
