<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Direct mode
    |--------------------------------------------------------------------------
    |
    | Direct mode lets an administrator type a database host, user and
    | password into a web form and have the application connect to it. On a
    | self-hosted box that is exactly the point — v1 and v2 sit side by side
    | and copying the credentials out of sys.config.php is the whole setup.
    | On a hosted, multi-tenant deployment it is a request-forgery surface
    | with no upside, since a hosted customer's v1 is on their own network
    | and unreachable anyway; they migrate by uploading a bundle instead.
    |
    | Turning this off hides the direct source in the UI and makes its routes
    | refuse, leaving bundle import as the only path.
    |
    */

    'direct_mode' => (bool) env('V1_MIGRATION_DIRECT_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | Chunk sizes
    |--------------------------------------------------------------------------
    |
    | Both php.ini files this application ships set memory_limit = 256M, and
    | real installs reach millions of activity rows, so nothing is ever read
    | whole. `read` is how many source rows are pulled per pass; `insert` is
    | how many are handed to a single multi-row INSERT. Each chunk commits in
    | its own transaction, which is what lets the queue worker be recycled
    | mid-run without losing the work already done.
    |
    */

    'chunk' => [
        'read' => (int) env('V1_MIGRATION_READ_CHUNK', 1000),
        'insert' => (int) env('V1_MIGRATION_INSERT_CHUNK', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bundle storage
    |--------------------------------------------------------------------------
    |
    | Where uploaded and extracted v1 bundles live. Outside the web root and
    | outside the files disk, so an extracted bundle is never mistaken for an
    | orphaned upload by the host's orphan scanner.
    |
    */

    'bundle_path' => env('V1_MIGRATION_BUNDLE_PATH', storage_path('app/v1-migration')),

];
