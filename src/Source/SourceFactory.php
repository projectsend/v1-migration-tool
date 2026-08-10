<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Source;

use Illuminate\Support\Facades\Crypt;
use ProjectSend\V1Migration\Models\MigrationRun;
use RuntimeException;

/**
 * Rebuilds a run's source from what the run row remembers.
 *
 * It has to be rebuildable rather than held: an import spans several job
 * invocations across worker restarts, so every process that picks the
 * run up reconstructs the connection or reopens the bundle from the same
 * stored description.
 *
 * The v1 database password is stored encrypted rather than in the clear,
 * because the run row is shown in a web interface and downloaded as a
 * report. It cannot simply be discarded after the first connection —
 * the next process needs it too.
 */
final class SourceFactory
{
    public function make(MigrationRun $run): MigrationSource
    {
        $source = $run->source;

        if ($run->mode === MigrationRun::MODE_BUNDLE) {
            $path = (string) ($source['bundle_path'] ?? '');

            if ($path === '') {
                throw new RuntimeException('This run has no bundle path.');
            }

            return new BundleSource($path);
        }

        LegacyDatabaseSource::connect([
            'host' => (string) ($source['host'] ?? '127.0.0.1'),
            'port' => (string) ($source['port'] ?? '3306'),
            'database' => (string) ($source['database'] ?? ''),
            'username' => (string) ($source['username'] ?? ''),
            'password' => $this->password($source),
        ]);

        $installPath = rtrim((string) ($source['install_path'] ?? ''), '/');

        return new LegacyDatabaseSource(
            prefix: (string) ($source['prefix'] ?? 'tbl_'),
            filesRoot: $installPath.'/upload/files',
            version: isset($source['version']) ? (string) $source['version'] : null,
            hasEncryptionKey: (bool) ($source['has_encryption_key'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function redact(array $source): array
    {
        unset($source['password_encrypted']);

        return $source;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function password(array $source): string
    {
        $encrypted = $source['password_encrypted'] ?? null;

        return is_string($encrypted) && $encrypted !== ''
            ? Crypt::decryptString($encrypted)
            : '';
    }
}
