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
 * This class exists for the rows where that does not hold. The plaintext
 * is gone, so nobody can repair them; what it can do is make sure they
 * fail the way an unknown password is supposed to fail.
 */
class LegacyPassword
{
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
     * All three bcrypt prefixes pass. The cost embedded in them is not
     * checked, because v2 re-hashes a stale one on the first successful
     * login.
     */
    public static function isVerifiable(string $hash): bool
    {
        return str_starts_with($hash, '$2y$')
            || str_starts_with($hash, '$2a$')
            || str_starts_with($hash, '$2b$');
    }

    /**
     * The digest to write into `users.password`.
     *
     * A verifiable one goes in as it is — that is the case this whole
     * migration is built around. Anything else is replaced with a bcrypt
     * hash of 64 random characters: a valid digest that nobody holds and
     * nobody can hold, one per account so two broken rows never share a
     * secret.
     *
     * The replacement is what turns a *broken* login into a *refused*
     * one. Left verbatim, a pre-bcrypt digest makes `Hash::check()` throw
     * on the sign-in form, and v2 renders that as a 500 — an error page,
     * on the first thing a migrated client touches, blamed on the new
     * install rather than on a password that stopped working years ago.
     * Replaced, the same person gets "these credentials do not match",
     * and "forgot password" puts them back in. Which is exactly what
     * preflight told the operator would happen.
     *
     * Nothing recoverable is discarded: `isVerifiable()` explains why a
     * digest this rejects was already unusable in v1.
     */
    public static function forImport(string $hash): string
    {
        return self::isVerifiable($hash)
            ? $hash
            : Hash::make(Str::random(64));
    }
}
