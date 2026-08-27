<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Transform;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * What v1 stored in `tbl_users.password`, and whether v2 can do
 * anything with it.
 *
 * Almost always the answer is "carry it across as it is". v1 hashes with
 * `password_hash($p, PASSWORD_DEFAULT, ['cost' => 8])` — bcrypt — and
 * v2's bcrypt driver verifies a `$2y$08$…` digest directly. That is the
 * whole design: no reset email, no forced change, people keep the
 * password they already had.
 *
 * This class exists for the rows where that does not hold: the ones
 * carrying a bcrypt digest under a different label, and the ones not
 * carrying bcrypt at all.
 */
class LegacyPassword
{
    /**
     * Bcrypt labels that differ from `$2y$` in the label and nothing else.
     *
     * All three name the same algorithm. `$2a$` is what crypt_blowfish
     * emitted before 2011; `$2y$` was added to mark digests from the fixed
     * implementation, and `$2b$` is OpenBSD's name for the same fix. A
     * digest under any of them verifies identically once relabelled —
     * checked here against ASCII, accented, high-bit and multi-byte
     * passwords, and against a 60-character one.
     *
     * `$2x$` is deliberately not in this list. It is not a spelling of
     * `$2y$`: it asks for the *old, broken* handling of bytes above 127 to
     * be reproduced on purpose, so relabelling it silently locks out
     * anybody whose password is not plain ASCII. It goes down the
     * unverifiable path below instead.
     */
    private const RELABELLED = ['$2a$', '$2b$'];

    /**
     * Whether v2 will be able to check this digest at a login prompt.
     *
     * Two shapes fail, and they fail differently:
     *
     *  - **Blank.** Some very old or half-created v1 accounts carry an
     *    empty password. `Hash::check()` returns false, so the account
     *    imports cleanly and then quietly refuses every sign-in.
     *  - **Another algorithm.** An md5 or sha1 left by a pre-bcrypt
     *    ProjectSend, or an argon2 digest from a PHP where
     *    `PASSWORD_DEFAULT` had moved on. v2's hasher verifies the
     *    algorithm, so these do not merely fail to match — `Hash::check()`
     *    *throws*, and the login errors instead of refusing.
     *
     * Neither account could sign into v1 either: v1 authenticates with
     * `password_verify()` and has no fallback for a pre-bcrypt digest. So
     * nothing is being taken away here — the digest was already dead, and
     * carrying it verbatim only decides which way its corpse fails.
     *
     * The relabelled prefixes count as verifiable because `forImport()`
     * relabels them. The cost embedded in a digest is not checked, because
     * v2 re-hashes a stale one on the first successful login.
     */
    public static function isVerifiable(string $hash): bool
    {
        return str_starts_with($hash, '$2y$')
            || self::relabel($hash) !== null;
    }

    /**
     * The digest to write into `users.password`.
     *
     * A `$2y$` digest goes in as it is — that is the case this whole
     * migration is built around.
     *
     * A `$2a$` or `$2b$` one goes in relabelled. This matters more than it
     * sounds: v2's hasher decides whether it recognises an algorithm with
     * `password_get_info()`, which answers "unknown" for both of those
     * labels even though `password_verify()` accepts them perfectly well.
     * So a digest carried across verbatim does not fail to match — it
     * makes the sign-in form throw, and v2 renders that as a 500. Only the
     * four label bytes change; everything after them, salt and digest
     * alike, is untouched, so the password itself is exactly as it was.
     *
     * Anything else is replaced with a bcrypt hash of 64 random
     * characters: a valid digest that nobody holds and nobody can hold,
     * one per account so two broken rows never share a secret. That is
     * what turns a *broken* login into a *refused* one — the person gets
     * "these credentials do not match", and "forgot password" puts them
     * back in. Which is exactly what preflight told the operator would
     * happen.
     *
     * Nothing recoverable is discarded: `isVerifiable()` explains why a
     * digest this rejects was already unusable in v1.
     */
    public static function forImport(string $hash): string
    {
        if (str_starts_with($hash, '$2y$')) {
            return $hash;
        }

        return self::relabel($hash) ?? Hash::make(Str::random(64));
    }

    /**
     * The same digest under the one label v2's hasher will look at, or
     * null if this is not one of the labels that can simply be renamed.
     */
    private static function relabel(string $hash): ?string
    {
        foreach (self::RELABELLED as $prefix) {
            if (str_starts_with($hash, $prefix)) {
                return '$2y$'.substr($hash, 4);
            }
        }

        return null;
    }
}
