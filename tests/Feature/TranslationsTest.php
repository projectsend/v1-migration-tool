<?php

declare(strict_types=1);

/**
 * This package owns its own catalogues, so it owns keeping them complete.
 *
 * Nothing in the host application can check this: its translation scan
 * reads the host's own directories and never looks inside packages/, so
 * a string added to the import screen without a translation would sit in
 * English in sixteen languages and nothing anywhere would say so. That
 * is exactly what happened before these catalogues existed: of the
 * forty-one strings this tool uses, exactly one was translated, and only
 * because the host happened to use the same word.
 */
$root = dirname(__DIR__, 2);

/** @return list<string> */
function translatableStrings(string $root): array
{
    $keys = [];

    foreach ([
        // t('…') on the React side, __('…') on the PHP side.
        ['dir' => $root.'/resources/js', 'extensions' => ['tsx', 'ts'], 'pattern' => '/\bt\(\s*\'((?:\\\\.|[^\'])*)\'/s'],
        ['dir' => $root.'/src', 'extensions' => ['php'], 'pattern' => '/\b__\(\s*\'((?:\\\\.|[^\'])*)\'/s'],
    ] as $target) {
        if (! is_dir($target['dir'])) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target['dir']));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), $target['extensions'], true)) {
                continue;
            }

            preg_match_all($target['pattern'], (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $key) {
                $keys[str_replace("\\'", "'", $key)] = true;
            }
        }
    }

    return array_keys($keys);
}

/** @return list<string> */
function catalogues(string $root): array
{
    return glob($root.'/lang/*.json') ?: [];
}

it('ships a catalogue for every language the application offers', function () use ($root) {
    $locales = array_map(
        fn (string $path): string => basename($path, '.json'),
        catalogues($root),
    );

    // The host's own set, as of writing. A language added there without
    // one here degrades to English rather than breaking, but it should
    // be a decision rather than an oversight.
    expect($locales)->toContain('es', 'de', 'fr', 'it', 'pt_BR', 'nl', 'ca', 'pl', 'cs', 'tr', 'ru', 'ja', 'zh_CN', 'vi', 'id', 'sw');
});

it('translates every string these screens actually use', function (string $path) use ($root) {
    /** @var array<string, string> $catalogue */
    $catalogue = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $missing = array_values(array_diff(translatableStrings($root), array_keys($catalogue)));

    expect($missing)->toBe([], basename($path).' is missing: '.implode(' | ', $missing));
})->with(fn () => catalogues(dirname(__DIR__, 2)));

it('keeps every placeholder intact', function (string $path) {
    /** @var array<string, string> $catalogue */
    $catalogue = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    foreach ($catalogue as $key => $translation) {
        preg_match_all('/:[a-zA-Z_]+/', $key, $expected);
        preg_match_all('/:[a-zA-Z_]+/', $translation, $actual);

        expect($actual[0])->toBe($expected[0], "placeholders differ for: {$key}");
    }
})->with(fn () => catalogues(dirname(__DIR__, 2)));

// Shipping the files is half of it. Without this registration they are
// sixteen JSON files nothing ever reads.
it('registers its catalogues with the framework', function () use ($root) {
    /** @var \Illuminate\Translation\FileLoader $loader */
    $loader = app('translator')->getLoader();

    // Normalised on both sides: the provider registers a path relative to
    // its own directory, which is correct and is not what realpath()
    // returns.
    $registered = array_map(
        fn (string $path): string => (string) realpath($path),
        $loader->jsonPaths(),
    );

    expect($registered)->toContain((string) realpath($root.'/lang'));
});
