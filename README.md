# ProjectSend v1 → v2 migration tool

Imports a ProjectSend **v1** install — its database and its uploaded files — into
ProjectSend **v2**.

This is deliberately *not* part of v2. Migrating happens once, if ever, and the engine
it needs — arbitrary database connections plus direct writes across the whole schema —
is not something every install should carry idle. Install it when you want it, remove it
when you're done.

```bash
composer require projectsend/v1-migration-tool
php artisan migrate          # creates this package's two tables
npm run build                # so its screen enters the frontend bundle
```

Then open **`/system/migrate`** as a staff user with the *Edit settings* permission.
There is no sidebar link on purpose — a one-time tool doesn't earn a permanent slot in
the navigation.

When the migration is done:

```bash
php artisan projectsend:migrate:reset --drop   # optional; also drops this package's tables
composer remove projectsend/v1-migration-tool
```

`--drop` throws away the v1 → v2 id map. Keep it if you may ever want to redirect old
`download.php?id=…` links, which is the only thing that can resolve them.

## What it needs from the host

| Requirement | Why |
|---|---|
| ProjectSend v2 with the schema this tool writes | Checked before anything runs — see `src/Host/HostTables.php` and `src/Preflight/HostSchemaCheck.php`. A mismatch is a hard stop, never a partial import |
| A **fresh** install — set up, not yet used | There are no merge semantics. See `src/Preflight/FreshInstallCheck.php` |
| The `staff` route middleware alias and an `edit_settings` Gate | The host's `IdentityServiceProvider` registers both |
| A running queue worker | A real import outlives any web request; the UI queues a job and polls a row |

## Two ways in

**Direct** — v1 and v2 on the same machine. Point the tool at v1's database and its
install directory; nothing is copied that doesn't have to be. This is the fast path: on
one filesystem, file bytes are hardlinked rather than duplicated, so 400 GB migrates in
seconds.

**Bundle** — v1 somewhere the v2 install cannot reach (a hosted ProjectSend, or simply a
different server). Run `bin/projectsend-v1-export.php` on the v1 box; it produces a
portable bundle you upload here. The exporter is a single dependency-free PHP file and
**never writes to the v1 install**.

Direct mode can be switched off entirely with `V1_MIGRATION_DIRECT_MODE=false` — letting
an administrator type an arbitrary database host into a web form is fine on a box they
own and unwanted on a hosted deployment.

## What it will not do

It reports these rather than guessing, and names every affected row:

- **Encrypted files.** v1 could encrypt files at rest; v2 has no equivalent, and the keys
  are wrapped by a master key that exists only in v1's `sys.config.php`.
- **Files on S3, GCS or Azure.** v1's per-file external storage doesn't map onto v2's
  single-bucket setting.
- **Per-file download limits** and **hidden assignments** — v2 has neither.
- **Two-factor secrets** — encrypted with v1's key; those users re-enrol.
- **v1 options with no v2 equivalent** — v2 has ~43 settings where v1 had ~180.

It also **cannot** add an `Action`, `Capability` or `Setting` case: all three are closed
PHP enums the host casts database columns to, so a value invented here would throw on
read for every row afterwards — including rows this tool never touched. Anything v1
logged that v2 has no vocabulary for is dropped and counted, never approximated.

## One thing changes for your users

v1 signs in with a **username**. v2 signs in with an **email address**. Every client's
login therefore changes, and v1 did not require emails to be unique — preflight refuses
to run until duplicates, blanks and invalid addresses are resolved, so nobody is silently
merged or locked out. Tell your clients before you cut over; the run report gives you the
list.

## Development

```bash
composer install
vendor/bin/pest
```

To exercise it against a real ProjectSend, `scripts/sim.sh` builds a disposable v2 with
this package installed and stages the seeded v1 fixtures into it:

```bash
scripts/sim.sh up               # a v2 of its own on :8192
scripts/sim.sh fixture small    # stage a v1 install
scripts/sim.sh run small        # preflight, import, verify
scripts/sim.sh reset            # undo it and try again
```

A development checkout cannot stand in for that instance: the tool imports into a
fresh install only, and a checkout has content and shares its file storage with
everything else you have been doing.

Tests run against a throwaway Testbench app with no host present, so the host tables are
replicated in `tests/Support/HostSchema.php`. That replica is a convenience, not the
contract — the contract is `src/Host/HostTables.php`, and it is verified against the real
database at preflight time.
