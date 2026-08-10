<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Source;

use RuntimeException;

/**
 * Reads a v1 install's `includes/sys.config.php` without executing it.
 *
 * Everything needed to connect is already in that file — database name,
 * host, credentials, table prefix — so on a same-machine upgrade the
 * operator should not have to retype any of it. Pointing at the
 * directory is enough.
 *
 * Parsed rather than `require`d, deliberately. It is a PHP file from
 * somebody's production install, and including it would run whatever is
 * in it inside the v2 application, in a process holding v2's own
 * database connection, to learn a database name.
 *
 * The presence of `ENCRYPTION_MASTER_KEY` is recorded and the key itself
 * is not: v2 cannot use it, and reading it into a process that writes
 * run records to a database is the wrong place for it to be.
 */
final class V1Config
{
    /**
     * @param  array<string, string>  $values
     */
    private function __construct(public readonly array $values) {}

    public static function read(string $installPath): self
    {
        $path = rtrim($installPath, '/').'/includes/sys.config.php';

        if (! is_file($path)) {
            throw new RuntimeException(
                "No includes/sys.config.php in {$installPath} — that is not a ProjectSend v1 install.",
            );
        }

        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("Could not read {$path}.");
        }

        $values = [];

        preg_match_all(
            '/define\s*\(\s*[\'"]([A-Z_0-9]+)[\'"]\s*,\s*(?:[\'"](.*?)[\'"]|([0-9]+)|(true|false))\s*\)/is',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $values[$match[1]] = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? $match[4] ?? '');
        }

        foreach (['DB_NAME', 'DB_HOST', 'DB_USER'] as $required) {
            if (! isset($values[$required])) {
                throw new RuntimeException("{$path} has no {$required}; it does not look like a ProjectSend config.");
            }
        }

        return new self($values);
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * v1's CURRENT_VERSION, which lives in includes/app.php rather than
     * the config — read so a direct-mode run identifies the install as
     * precisely as a bundle does, and so a report says "r2098" instead
     * of "v1".
     */
    public function version(string $installPath): ?string
    {
        $path = rtrim($installPath, '/').'/includes/app.php';
        $source = is_file($path) ? (file_get_contents($path) ?: '') : '';

        return preg_match("/CURRENT_VERSION'\\s*,\\s*'([^']+)'/", $source, $matches) === 1
            ? $matches[1]
            : null;
    }

    public function hasEncryptionKey(): bool
    {
        return ($this->values['ENCRYPTION_MASTER_KEY'] ?? '') !== '';
    }
}
