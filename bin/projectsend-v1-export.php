<?php

declare(strict_types=1);

/**
 * ProjectSend v1 → v2 exporter.
 *
 * Run this on the machine your ProjectSend **v1** install lives on. It
 * produces a portable bundle that ProjectSend v2's migration tool can
 * import, for the case where v2 cannot reach v1's database or disk —
 * onboarding into a hosted ProjectSend, or simply two different servers.
 *
 * It is one file with no dependencies on purpose: it has to run on a
 * decade-old install whose Composer directory you would rather not
 * touch, and it must be reviewable by whoever is about to run it against
 * their production database.
 *
 * **It never writes to the v1 install.** No marker rows, no lock file,
 * no maintenance flag. The source has to stay usable — and rollback-able
 * — after a failed import, and the only way to guarantee that is to
 * never touch it.
 *
 * Usage:
 *
 *   php projectsend-v1-export.php --out=/path/to/bundle
 *   php projectsend-v1-export.php --install=/var/www/projectsend --out=./bundle
 *   php projectsend-v1-export.php --preflight
 *   php projectsend-v1-export.php --out=./bundle --files=copy
 *
 * In the linuxserver/projectsend container, where the app lives at
 * /app/www/public, uploads are a symlink to /data/projectsend and the
 * config is a symlink to /config/projectsend/sys.config.php:
 *
 *   docker exec projectsend php /app/www/public/projectsend-v1-export.php \
 *       --out=/data/ps-export
 *
 * — which lands the bundle on the host's own /data bind mount. Both
 * symlinks are followed, so nothing special is needed beyond that.
 *
 * Options:
 *
 *   --install=DIR   v1's document root. Default: search upwards from
 *                   this script for includes/sys.config.php.
 *   --out=DIR       Where to write the bundle. Required unless
 *                   --preflight.
 *   --files=MODE    inventory (default) | copy | none
 *                   `inventory` records every file's path and size
 *                   without moving a byte — the only sane choice above a
 *                   few gigabytes. Transfer the upload directory
 *                   separately (rsync, and take your time), and point
 *                   the import at it. `copy` puts the bytes in the
 *                   bundle, which is convenient and doubles the disk you
 *                   need.
 *   --preflight     Report what is here and exit without writing.
 *   --force         Overwrite a non-empty output directory.
 */

const TABLES = [
    'users', 'roles', 'role_permissions', 'groups', 'members',
    'members_requests', 'categories', 'categories_relations', 'folders',
    'files', 'files_relations', 'downloads', 'actions_log', 'options',
    'custom_fields', 'custom_field_values', 'user_limit_upload_to',
    'integrations',
];

const CHUNK = 2000;

exit(main($argv));

function main(array $argv): int
{
    $options = parseArguments($argv);

    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This script must be run from the command line.\n");

        return 1;
    }

    try {
        $install = locateInstall($options['install'] ?? null);
        $config = readConfig($install);
        $pdo = connect($config);

        $context = [
            'install' => $install,
            'config' => $config,
            'pdo' => $pdo,
            'filesDir' => $install.'/upload/files',
            'kind' => detectInstallKind($install),
        ];

        report($context);

        if (isset($options['preflight'])) {
            return 0;
        }

        $out = $options['out'] ?? null;

        if ($out === null) {
            fwrite(STDERR, "\nNothing written. Pass --out=DIR to write a bundle, or --preflight to only look.\n");

            return 1;
        }

        writeBundle($context, $out, $options['files'] ?? 'inventory', isset($options['force']));

        out("\nBundle written to {$out}");
        out('Upload it to ProjectSend v2 at /system/migrate.');

        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, "\nError: ".$e->getMessage()."\n");

        return 1;
    }
}

/**
 * @return array<string, string>
 */
function parseArguments(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $matches) === 1) {
            $options[$matches[1]] = $matches[2] ?? '1';
        }
    }

    return $options;
}

/**
 * v1's document root — the directory holding includes/sys.config.php.
 *
 * Searched upwards from this script rather than assumed, because the
 * documented way to run this is to drop it into the docroot, and the
 * convenient way is to run it from anywhere with --install.
 */
function locateInstall(?string $given): string
{
    $candidates = $given !== null
        ? [$given]
        : [getcwd() ?: '.', __DIR__];

    foreach ($candidates as $start) {
        $directory = realpath($start);

        while ($directory !== false && $directory !== '/' && $directory !== '') {
            if (is_file($directory.'/includes/sys.config.php')) {
                return $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }
    }

    throw new RuntimeException(
        'Could not find includes/sys.config.php. Run this from inside your ProjectSend v1 '
        .'directory, or pass --install=/path/to/projectsend.',
    );
}

/**
 * Read v1's constants without executing its config file.
 *
 * sys.config.php is a list of define() calls, but it is also somebody's
 * production configuration, and `require`ing an arbitrary file from a
 * decade-old install is not something this script should do to find out
 * a database name.
 *
 * @return array<string, string>
 */
function readConfig(string $install): array
{
    $path = $install.'/includes/sys.config.php';
    $source = file_get_contents($path);

    if ($source === false) {
        throw new RuntimeException("Could not read {$path}.");
    }

    $config = [];

    preg_match_all(
        '/define\s*\(\s*[\'"]([A-Z_0-9]+)[\'"]\s*,\s*(?:[\'"](.*?)[\'"]|([0-9]+)|(true|false))\s*\)/is',
        $source,
        $matches,
        PREG_SET_ORDER,
    );

    foreach ($matches as $match) {
        $config[$match[1]] = $match[2] !== '' ? $match[2] : ($match[3] ?? $match[4] ?? '');
    }

    foreach (['DB_NAME', 'DB_HOST', 'DB_USER'] as $required) {
        if (! isset($config[$required])) {
            throw new RuntimeException("{$path} has no {$required} — is this a ProjectSend install?");
        }
    }

    $config += ['DB_PORT' => '3306', 'DB_PASSWORD' => '', 'TABLES_PREFIX' => 'tbl_'];

    return $config;
}

function connect(array $config): PDO
{
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['DB_HOST'],
            $config['DB_PORT'],
            $config['DB_NAME'],
        ),
        $config['DB_USER'],
        $config['DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // Rows are streamed, not buffered: an actions_log with
            // millions of rows must not have to fit in memory, and this
            // script runs under whatever memory_limit v1 was configured
            // with.
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,

            // The v2 side pins UTC too. MySQL converts TIMESTAMP columns
            // through the session timezone on the way out, so without
            // this every exported date shifts by whatever the server
            // happens to be set to.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ],
    );
}

/**
 * Whether this looks like the linuxserver container or a plain install.
 *
 * Only used to make the report recognisable — nothing branches on it.
 * The symlinks that make the container different are followed by
 * realpath() either way.
 */
function detectInstallKind(string $install): string
{
    if (is_link($install.'/upload') || is_link($install.'/includes/sys.config.php')) {
        return 'container';
    }

    return (is_file('/.dockerenv') || is_file('/run/.containerenv')) ? 'container' : 'manual';
}

function report(array $context): void
{
    $config = $context['config'];

    out('ProjectSend v1 export');
    out(str_repeat('-', 60));
    out(sprintf('%-22s %s', 'Install', $context['install']));
    out(sprintf('%-22s %s', 'Kind', $context['kind']));
    out(sprintf('%-22s %s', 'Database', $config['DB_NAME'].' on '.$config['DB_HOST']));
    out(sprintf('%-22s %s', 'Table prefix', $config['TABLES_PREFIX']));
    out(sprintf('%-22s %s', 'Uploads', $context['filesDir']));

    $options = readOptions($context);
    out(sprintf('%-22s %s', 'Version', currentVersion($context['install'])));
    out(sprintf('%-22s %s', 'Timezone', $options['timezone'] ?? '(unset)'));
    out(sprintf(
        '%-22s %s',
        'Upload folders',
        ($options['uploads_organize_folders_by_date'] ?? '0') === '1' ? 'YYYY/MM' : 'flat',
    ));
    out(sprintf(
        '%-22s %s',
        'Encryption key',
        isset($config['ENCRYPTION_MASTER_KEY']) ? 'present (not exported)' : 'none',
    ));

    out('');
    out('Rows');
    out(str_repeat('-', 60));

    foreach (TABLES as $table) {
        out(sprintf('%-22s %s', $table, number_format(countRows($context, $table))));
    }

    $inventory = inventorySummary($context, $options);

    out('');
    out('Files');
    out(str_repeat('-', 60));
    out(sprintf('%-22s %s', 'Rows with bytes', number_format($inventory['present'])));
    out(sprintf('%-22s %s', 'Rows without bytes', number_format($inventory['missing'])));
    out(sprintf('%-22s %s', 'Total size', formatBytes($inventory['bytes'])));

    if ($inventory['encrypted'] > 0) {
        out('');
        out(sprintf(
            'NOTE: %s file(s) are encrypted at rest. ProjectSend v2 has no equivalent, and the',
            number_format($inventory['encrypted']),
        ));
        out('      keys are wrapped by this install\'s ENCRYPTION_MASTER_KEY. They will be listed');
        out('      and skipped by the import.');
    }

    if ($inventory['external'] > 0) {
        out('');
        out(sprintf(
            'NOTE: %s file(s) live on external storage (S3/GCS/Azure) rather than this disk.',
            number_format($inventory['external']),
        ));
        out('      They will be listed and skipped by the import.');
    }
}

/**
 * @return array<string, string|null>
 */
function readOptions(array $context): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $options = [];

    foreach (query($context, 'SELECT name, value FROM '.table($context, 'options').' ORDER BY id') as $row) {
        $options[(string) $row['name']] = $row['value'];
    }

    return $cache = $options;
}

function currentVersion(string $install): string
{
    $path = $install.'/includes/app.php';
    $source = is_file($path) ? (file_get_contents($path) ?: '') : '';

    return preg_match("/CURRENT_VERSION'\s*,\s*'([^']+)'/", $source, $matches) === 1
        ? $matches[1]
        : 'unknown';
}

/**
 * @return array{present: int, missing: int, bytes: int, encrypted: int, external: int}
 */
function inventorySummary(array $context, array $options): array
{
    $summary = ['present' => 0, 'missing' => 0, 'bytes' => 0, 'encrypted' => 0, 'external' => 0];

    foreach (walkFiles($context, $options) as $entry) {
        if ($entry['encrypted']) {
            $summary['encrypted']++;
        }

        if ($entry['storage_type'] !== 'local') {
            $summary['external']++;

            continue;
        }

        if ($entry['present']) {
            $summary['present']++;
            $summary['bytes'] += $entry['size'];
        } else {
            $summary['missing']++;
        }
    }

    return $summary;
}

/**
 * Every file row with the path its bytes should be at.
 *
 * The path is v1's `url` column, optionally under YYYY/MM — and the
 * month directory is **zero-padded on disk** while disk_folder_month
 * stores a plain integer. v1 re-pads it when reading; so does this.
 *
 * @return Generator<array{id: int, path: string, size: int, present: bool, encrypted: bool, storage_type: string}>
 */
function walkFiles(array $context, array $options): Generator
{
    $dated = ($options['uploads_organize_folders_by_date'] ?? '0') === '1';
    $sql = 'SELECT id, url, size, encrypted, storage_type, disk_folder_year, disk_folder_month FROM '
        .table($context, 'files').' ORDER BY id';

    foreach (query($context, $sql) as $row) {
        $path = (string) $row['url'];

        if ($dated && $row['disk_folder_year'] !== null && $row['disk_folder_month'] !== null) {
            $path = sprintf('%04d/%02d/%s', (int) $row['disk_folder_year'], (int) $row['disk_folder_month'], $path);
        }

        $full = $context['filesDir'].'/'.$path;
        $present = is_file($full);

        yield [
            'id' => (int) $row['id'],
            'path' => $path,
            'size' => $present ? (int) (filesize($full) ?: 0) : (int) $row['size'],
            'present' => $present,
            'encrypted' => (int) $row['encrypted'] === 1,
            'storage_type' => (string) ($row['storage_type'] ?? 'local'),
        ];
    }
}

function writeBundle(array $context, string $out, string $filesMode, bool $force): void
{
    if (is_dir($out) && ! $force && (scandir($out) ?: []) !== ['.', '..']) {
        throw new RuntimeException("{$out} is not empty. Pass --force to overwrite it.");
    }

    makeDirectory($out.'/tables');

    $counts = [];

    out('');
    out('Writing tables');

    foreach (TABLES as $table) {
        $counts[$table] = writeTable($context, $out, $table);
        out(sprintf('  %-22s %s rows', $table, number_format($counts[$table])));
    }

    $options = readOptions($context);
    $copied = writeInventory($context, $out, $options, $filesMode);

    $manifest = [
        'generated_at' => gmdate('c'),
        'generated_by' => 'projectsend-v1-export.php',
        'version' => currentVersion($context['install']),
        'database_version' => isset($options['database_version']) ? (int) $options['database_version'] : null,
        'install_kind' => $context['kind'],
        'table_prefix' => $context['config']['TABLES_PREFIX'],
        'uploads_organized_by_date' => ($options['uploads_organize_folders_by_date'] ?? '0') === '1',

        // Recorded, never carried. The key wraps every per-file key and
        // exists only in this install's sys.config.php; putting it in a
        // bundle that travels by email or object storage would be the
        // single worst thing this script could do.
        'has_encryption_key' => isset($context['config']['ENCRYPTION_MASTER_KEY']),

        'timezone' => $options['timezone'] ?? null,
        'counts' => $counts,
        'files_mode' => $filesMode,
        'files_copied' => $copied,
        'warnings' => bundleWarnings($context, $options),
    ];

    file_put_contents(
        $out.'/manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}

function writeTable(array $context, string $out, string $table): int
{
    $handle = gzopen($out.'/tables/'.$table.'.ndjson.gz', 'wb9');

    if ($handle === false) {
        throw new RuntimeException("Could not write {$table}.ndjson.gz.");
    }

    $written = 0;
    $afterId = 0;

    // Keyset paging rather than one streaming cursor: an unbuffered
    // query holds the connection for its whole life, and gzipping five
    // million rows takes long enough for that to matter.
    while (true) {
        $statement = $context['pdo']->prepare(
            'SELECT * FROM '.table($context, $table).' WHERE id > ? ORDER BY id LIMIT '.CHUNK,
        );
        $statement->execute([$afterId]);

        $rows = $statement->fetchAll();

        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            gzwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            $afterId = (int) $row['id'];
            $written++;
        }
    }

    gzclose($handle);

    return $written;
}

function writeInventory(array $context, string $out, array $options, string $filesMode): int
{
    $handle = fopen($out.'/files.ndjson', 'wb');

    if ($handle === false) {
        throw new RuntimeException('Could not write files.ndjson.');
    }

    $copied = 0;

    if ($filesMode === 'copy') {
        out('');
        out('Copying files');
    }

    foreach (walkFiles($context, $options) as $entry) {
        fwrite($handle, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");

        if ($filesMode !== 'copy' || ! $entry['present'] || $entry['storage_type'] !== 'local') {
            continue;
        }

        $target = $out.'/files/'.$entry['path'];
        makeDirectory(dirname($target));

        if (copy($context['filesDir'].'/'.$entry['path'], $target)) {
            $copied++;

            if ($copied % 500 === 0) {
                out('  '.number_format($copied).' files');
            }
        }
    }

    fclose($handle);

    return $copied;
}

/**
 * @return list<string>
 */
function bundleWarnings(array $context, array $options): array
{
    $warnings = [];
    $summary = inventorySummary($context, $options);

    if ($summary['missing'] > 0) {
        $warnings[] = sprintf(
            '%d file row(s) have no bytes on disk in this install.',
            $summary['missing'],
        );
    }

    if ($summary['encrypted'] > 0) {
        $warnings[] = sprintf(
            '%d file(s) are encrypted at rest; their keys are wrapped by this install\'s master key.',
            $summary['encrypted'],
        );
    }

    if ($summary['external'] > 0) {
        $warnings[] = sprintf(
            '%d file(s) are stored externally (S3/GCS/Azure) rather than on this disk.',
            $summary['external'],
        );
    }

    return $warnings;
}

function table(array $context, string $name): string
{
    return '`'.$context['config']['TABLES_PREFIX'].$name.'`';
}

function countRows(array $context, string $table): int
{
    $statement = $context['pdo']->query('SELECT COUNT(*) AS c FROM '.table($context, $table));
    $row = $statement->fetch();
    $statement->closeCursor();

    return (int) ($row['c'] ?? 0);
}

/**
 * @return Generator<array<string, mixed>>
 */
function query(array $context, string $sql): Generator
{
    $statement = $context['pdo']->query($sql);

    while (($row = $statement->fetch()) !== false) {
        yield $row;
    }

    $statement->closeCursor();
}

function makeDirectory(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, 0o755, true) && ! is_dir($path)) {
        throw new RuntimeException("Could not create {$path}.");
    }
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit = 0;
    $value = (float) $bytes;

    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return sprintf('%.1f %s', $value, $units[$unit]);
}

function out(string $line): void
{
    fwrite(STDOUT, $line."\n");
}
