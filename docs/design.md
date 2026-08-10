# Importing ProjectSend v1 into v2

**Status: shipped.** · **Audience:** whoever maintains this package, or is deciding whether to trust it with a production install.

---

## The idea in one paragraph

ProjectSend v2 is a rewrite: different language stack, different schema, different login identity. Everyone who uses ProjectSend today is on v1, so without a way across, v2 is only reachable by people starting from zero. This package reads a v1 installation — its database and its uploaded files — and writes it into a fresh v2. It is installed on purpose, run once, and removed.

## Why it is not part of ProjectSend

The host's own placement rule asks one question: *does this code need to be physically absent from the product, not merely unreachable through the UI?* The usual reasons are injection risk or vendor trust. This one answers yes for a third reason — **lifecycle**. Migrating happens once, if ever, for a minority of installations, and the machinery it needs is a standing liability to carry: it opens arbitrary database connections and writes directly across the entire host schema. An installation that will never migrate should not ship an idle engine that can rewrite its own database.

That decision has consequences the rest of this document keeps returning to.

## What being a package forbids

| Cannot | Because | What is done instead |
|---|---|---|
| Add an `Action`, `Capability` or `Setting` case | All three are closed PHP enums the host casts database columns to. A value invented here throws when the row is **read** — and the activity log is read a page at a time, so one bad row takes out the screen for every entry beside it | Map onto existing cases and report what was dropped; keep run records in this package's own tables; gate direct mode on this package's own config |
| Reference host classes at build or test time | The package is built and tested against a throwaway Testbench app with no host present | Bulk writes go through the query builder against table *names*; the handful of small-N host classes are resolved from the container behind `class_exists()` guards (`Host\HostBridge`) |
| Add a sidebar entry | The host's sidebar is a hardcoded array with no hook | `/system/migrate` is the documented URL. A one-time tool does not earn a permanent navigation slot |

> Writing into another application's schema by name is a contract nothing enforces at compile time. `Host\HostTables` declares every table and column this package writes, and `Preflight\HostSchemaCheck` verifies it against the live database **before the first write**. A missing column is a hard stop, never a partial import.

## The one thing the host had to change

`resources/js/app.tsx` globbed package Inertia pages from `packages/*` only — the dev checkouts' symlinked clones. A `composer require`d package lands in `vendor/`, so its pages never entered the build. The glob now covers `vendor/*/*`, which is where a path repository symlinks a local clone too, so one location serves both.

`resources/views/app.blade.php` does the same lookup server-side, to name the page's chunk for `@vite`. **These two are halves of one lookup and must stay in step.** When only the JavaScript half was updated, the page type-checked, built cleanly, and answered `500 Unable to locate file in Vite manifest`. Neither `npm run types` nor `npm run build` can catch that; only loading the page can.

## Two ways in, one importer

`Source\MigrationSource` is the seam. `LegacyDatabaseSource` reads a live v1 database and its upload directory; `BundleSource` reads gzipped NDJSON produced by `bin/projectsend-v1-export.php` on a machine this one cannot reach. Everything downstream is written once.

| | Direct | Bundle |
|---|---|---|
| For | v1 and v2 on one machine | v1 on the customer's own infrastructure |
| Credentials | Read from v1's `includes/sys.config.php`; the operator gives a path | Not needed — the export already happened |
| File bytes | Can be hardlinked | Carried in the bundle, or transferred separately |
| Availability | `V1_MIGRATION_DIRECT_MODE`, on by default | Always |

Rows are always fetched by keyset on `id` — every v1 table has an auto-increment primary key — never by OFFSET, which stops being either correct or cheap five million rows into an activity log. Bundle reads are forward-only, because gzip cannot seek backwards without re-inflating from byte zero; a row read past the end of a chunk is held rather than pushed back.

## Interruption is the normal case

The production image runs `queue:work --max-time=3600`, replacing the worker every hour, and an import of a large installation takes longer than that. So a run is not one job. `MigrationRunner` works until its budget is nearly spent, records the cursor on the run row, and `RunMigrationJob` re-dispatches itself.

Two rules make that safe:

- **Each chunk commits on its own.** Never one transaction around a phase, let alone a run.
- **The id map is written in the same transaction as the rows it maps.** If those come apart, a resumed run sees rows that exist and are unmapped, treats them as not yet imported, and duplicates them.

`v1_migration_id_map` is the spine — phases share no memory, and every cross-phase reference resolves through it. It outlives the run on purpose: v1 mailed out `download.php?id=417&token=…` links for years, and this table is the only thing that could ever resolve them.

## Phase order

Derived from v1's foreign-key graph — the reverse of the delete order v1 itself uses in `lib/Reset.php`. Settings, mail settings, roles, role permissions, accounts, staff→client assignments, groups, members, membership requests, categories, folders, custom fields and their values, files, file sharing, file categories, downloads, activity, finalise.

History is last because it is an order of magnitude larger than everything else, it references files and accounts that must already exist, and it is the one phase an operator might reasonably abandon — at which point everything that matters is already in.

## Things that will bite

**Strings are half-encoded, inconsistently.** v1's `encode_html()` is `htmlentities()` followed by `nl2br()`, and `htmlentities()` only covers the HTML entity table — so one stored value routinely holds both encoded and raw UTF-8 at once (`&Lambda;&omicron;&gamma;…ά&sigmaf;`). `Transform\LegacyText` strips line breaks **before** decoding entities, and that order is the whole point: nl2br ran last in v1, so its breaks are literal `<br />` while a `<br />` the user typed was already `&lt;br /&gt;`. Decode first and the two become indistinguishable. It also decodes once, not repeatedly — rescuing a literal `&amp;` would mangle every company name containing a plain `&`, which is far more common.

**Disk quotas are megabytes, despite the `bigint` column.** v1 multiplies by 1048576 at the point of use. Treating them as bytes would divide every quota by a million and silently make every client unlimited. Verified against v1's code, not its column types.

**Folders arrive private no matter what v1 says.** v1's `folders.public` is 1 on every row of every install because `Folder::create()` ignores the value it is handed. The column records a bug, not an intention; in v2 it genuinely publishes a folder. Copying it would take an entire folder tree public in one step.

**Who is a client is decided by role *name*.** v1 still carries a `level` column that `Users::create()` stopped writing years ago — on a real install every account but the installer's own admin has `level = 0`, so a tool keying off it classifies the whole staff as clients. v1 asks `Roles::isClientRole()`, which is `$name === 'Client'`. So does this.

**Categories are a tree in v1 and flat in v2.** Every category that had a parent takes its whole ancestry as its name — `Clients / Acme / Invoices` — and a root category keeps its bare name. Nothing merges and nothing is dropped, so no file loses a tag.

The first implementation kept the leaf name and qualified it only on collision. That produces shorter names and was wrong: with three `Invoices` in different branches, *which* one got to keep the bare name was decided by v1's row order, so an administrator saw `Invoices`, `Globex / Invoices` and `Archive / Invoices` side by side with nothing explaining why one was special. Qualifying all of them costs verbosity and buys an install where every category name means the same kind of thing. Two guards on top: `categories.name` is a `varchar(255)`, so an over-long path drops leading ancestors (`… / Globex / Invoices`) rather than overflowing — the deepest part is the specific one; and two siblings sharing a name make two identical paths, which get a ` (2)` suffix rather than one of them losing its files.

Worth knowing when reading the fixtures: the seeder gave every category a globally unique name (`Pending 8`, `Web Ready 15`), so none of them exercise a name collision. That is a fixture artifact, not what real installs look like — `tests/Feature/CategoriesPhaseTest.php` is where the collision, depth, cycle and dangling-parent cases actually live.

**Slugs cannot go through the host.** `HasUniqueSlug::uniqueSlugFrom()` runs a query per candidate. On 200,000 files mostly called "Final", that is the entire import. `Transform\SlugReserver` follows the same rules in memory.

**Reset needs its own delete order.** The first undo reversed the contract listing, which carries no dependency meaning, and failed on `users.role_id` (ON DELETE RESTRICT) with half the install already gone. `HostTables::deleteOrder()` is explicit, and a test keeps it in step with what gets written.

## Detected and reported, never guessed

Preflight blocks; the operator acknowledges; the run skips and lists.

| v1 feature | Why not |
|---|---|
| Files encrypted at rest | v2 has none, and the per-file keys are wrapped by a master key that exists only in v1's `sys.config.php` |
| Files on S3, GCS or Azure | v1 configures storage per file; v2 has one bucket |
| Per-file download limits | v2 caps downloads on share links, not files |
| Hidden assignments | v2 has no hidden state; creating them would show people files that were hidden from them |
| Two-factor secrets | Encrypted with v1's key |
| Duplicate, blank or invalid emails | **A blocker, not a skip.** v1 signs in by username and lets accounts share an address; v2 signs in by email and cannot. Picking a winner is deciding which client loses access |

Email templates and LDAP settings are deliberately not carried. v1's template bodies use a different placeholder vocabulary, so importing them verbatim produces emails with broken tokens — worse than v2's defaults.

## Testing map

| File | Asserts |
|---|---|
| `tests/Feature/MigrationScreenTest.php` | **Start here.** A whole import through `RunMigrationJob`: accounts decoded, quotas in the right unit, passwords untouched, settings JSON-encoded as the host's cast reads them, the baseline recorded. Plus the duplicate-email blocker leaving nothing behind |
| `tests/Feature/HostSchemaCheckTest.php` | The host contract, in both directions: a missing table or column blocks; a column this tool never writes does not |
| `tests/Feature/BundleSourceTest.php` | Keyset paging, resuming mid-table, duplicate option names, path traversal refused |
| `tests/Feature/IdMapTest.php` | Preloaded and chunked lookups, skips recorded, two runs kept apart |
| `tests/Unit/LegacyTextTest.php` | Entity/raw/mixed decoding, and the ordering trap that keeps a typed `<br />` |
| `tests/Unit/ActionMapTest.php` | What maps, what is dropped, and why per-assignment visibility is not `file.made_public` |
| `tests/Unit/OptionMapTest.php` | Types, units, inversions, junk rows tolerated |
| `tests/Unit/HostTablesTest.php` | Delete order covers what is written, children first |
| `tests/Feature/CategoriesPhaseTest.php` | The tree flattening: full-path naming, order-independence, over-long paths, identical sibling names, cycles, dangling parents |

Beyond the suite, every release should be run against the three seeded v1 fixtures (`ps-small`, `ps-messy`, `ps-large`) and reconciled with `projectsend:migrate:verify`.

## Deliberately not built

- **Merging into a live v2 install.** No conflict policy, no merge semantics. Fresh-install-only is what makes `:reset` safe, and `:reset` is what makes the tool worth trying.
- **Resuming an abandoned run.** Chunk checkpointing within a run is mandatory; picking up a run somebody walked away from is not.
- **Uploading a bundle through the browser.** A bundle carrying file bytes is not a form post, and one that does not still needs its files moved separately — both end with the operator putting something on the server.
- **Migrating thumbnails.** A derived cache; v2 regenerates on first request.
- **Legacy URL redirects.** Valuable, and possible because the id map is kept — but it belongs in the host, since it has to outlive this package.
- **Building v2 features to receive v1 data.** At-rest encryption, per-file download caps, hidden assignments, category trees. Reported, not invented.
