<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Console;

use Illuminate\Support\Facades\Crypt;
use ProjectSend\V1Migration\Models\MigrationRun;
use ProjectSend\V1Migration\Source\V1Config;
use RuntimeException;

/**
 * Turns command-line flags into the stored description of a source.
 *
 * The convenience that matters is `--v1-path`: everything needed to
 * connect is already in that install's `includes/sys.config.php`, so on
 * a same-machine upgrade the operator points at a directory and types
 * nothing else. Explicit flags override what is found there, for the
 * case where v1's database has moved since.
 */
trait ResolvesSource
{
    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function resolveSource(): array
    {
        $bundle = $this->option('bundle');

        if (is_string($bundle) && $bundle !== '') {
            return [MigrationRun::MODE_BUNDLE, ['bundle_path' => rtrim($bundle, '/')]];
        }

        $installPath = $this->option('v1-path');

        if (! is_string($installPath) || $installPath === '') {
            throw new RuntimeException(
                'Give the tool a source: --bundle=/path/to/bundle, or --v1-path=/path/to/projectsend for an install on this machine.',
            );
        }

        $installPath = rtrim($installPath, '/');
        $config = V1Config::read($installPath);

        return [MigrationRun::MODE_DIRECT, [
            'install_path' => $installPath,
            'host' => $this->stringOption('db-host') ?? $config->get('DB_HOST', '127.0.0.1'),
            'port' => $this->stringOption('db-port') ?? $config->get('DB_PORT', '3306'),
            'database' => $this->stringOption('db-name') ?? $config->get('DB_NAME'),
            'username' => $this->stringOption('db-user') ?? $config->get('DB_USER'),
            'password_encrypted' => Crypt::encryptString(
                $this->stringOption('db-password') ?? $config->get('DB_PASSWORD'),
            ),
            'prefix' => $this->stringOption('prefix') ?? $config->get('TABLES_PREFIX', 'tbl_'),
            'has_encryption_key' => $config->hasEncryptionKey(),
            'version' => $config->version($installPath),
        ]];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
