<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Tests\Support;

/**
 * Password digests shaped like the ones a real v1 install holds.
 *
 * A class rather than a literal in each test, because a *truncated*
 * bcrypt string — the obvious thing to type into a fixture — passes this
 * tool's prefix check and then throws inside Laravel's hasher. A test
 * built on one would claim a password survived the import when signing
 * in with it would 500.
 */
final class V1Passwords
{
    /**
     * bcrypt at cost 8, sixty characters: what
     * `password_hash($p, PASSWORD_DEFAULT, ['cost' => 8])` writes.
     */
    public static function bcrypt(): string
    {
        return '$2y$08$fSwmDKEGICEwhrQy9OxPBueht02q0ru4ohU6Y2aiFro6/SQGkWIQW';
    }
}
