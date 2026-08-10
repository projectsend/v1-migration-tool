<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

use Illuminate\Support\Facades\DB;
use ProjectSend\V1Migration\Host\HostTables;
use ProjectSend\V1Migration\MigrationContext;
use ProjectSend\V1Migration\Models\MigrationIdMap;
use ProjectSend\V1Migration\Source\V1Tables;
use ProjectSend\V1Migration\Transform\LegacyText;

/**
 * Accounts.
 *
 * The three things worth knowing:
 *
 * **Who is a client is decided by role name, not by level or id.** v1
 * still carries a `level` column, but `Users::create()` has not written
 * it for years — on a real install every account except the installer's
 * own admin has `level = 0`, so a tool keying off it classifies the
 * entire staff as clients. v1 itself asks `Roles::isClientRole()`, which
 * is `$name === 'Client'`. So does this.
 *
 * **The email address becomes the login.** v1 signs in with a username;
 * v2 has no username column at all. Preflight has already refused to
 * start if any two accounts share an address, so by the time this runs
 * the mapping is one-to-one — but the *people* do not know that yet,
 * which is why the report says so in as many words.
 *
 * **Passwords carry across untouched.** v1 hashes with
 * `password_hash($p, PASSWORD_DEFAULT, ['cost' => 8])`, giving a
 * `$2y$08$…` string that Laravel's bcrypt driver verifies directly. No
 * reset email, no forced change: everyone's existing password keeps
 * working, which removes the single largest reason a migration gets
 * abandoned halfway.
 */
final class UsersPhase extends TablePhase
{
    /** @var array<int, bool>|null v1 role id => is that role "Client" */
    private ?array $clientRoles = null;

    /** @var array<string, int>|null lowercased email => existing v2 user id */
    private ?array $existingEmails = null;

    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Accounts';
    }

    protected function table(): string
    {
        return V1Tables::USERS;
    }

    protected function process(MigrationContext $context, array $rows): void
    {
        $context->idMap->preload(MigrationIdMap::ENTITY_ROLE);

        $clientRoles = $this->clientRoles($context);
        $existing = $this->existingEmails();
        $now = now();

        foreach ($rows as $row) {
            $sourceId = (int) $row['id'];
            $email = trim((string) ($row['email'] ?? ''));
            $key = mb_strtolower($email);

            // The v2 setup administrator is already in the database, and
            // is very often the same person as v1's admin. Mapping onto
            // them rather than failing on the unique index keeps
            // everything that account owns — files, log entries —
            // attached to the account they are already signed in as.
            if (isset($existing[$key])) {
                $context->idMap->record(MigrationIdMap::ENTITY_USER, $sourceId, $existing[$key], 'matched an account this install already had');
                $context->count($this->key(), 'matched_existing');

                continue;
            }

            $v1RoleId = (int) ($row['role_id'] ?? 0);
            $isClient = $clientRoles[$v1RoleId] ?? false;
            $denied = (int) ($row['account_denied'] ?? 0) === 1;

            if ($denied) {
                // v2 has no "denied" state — a denied request is simply
                // an account that was never activated.
                $context->count($this->key(), 'denied_imported_inactive');
            }

            $id = (int) DB::table(HostTables::USERS)->insertGetId([
                'type' => $isClient ? 'client' : 'staff',
                'role_id' => $context->idMap->lookup(MigrationIdMap::ENTITY_ROLE, $v1RoleId ?: null),
                'active' => ! $denied && (int) ($row['active'] ?? 1) === 1,
                'account_requested' => (int) ($row['account_requested'] ?? 0) === 1,
                'name' => LegacyText::line((string) ($row['name'] ?? '')) ?: $email,
                'email' => $email,
                'password' => (string) ($row['password'] ?? ''),

                // v1's max_disk_quota is megabytes despite its bigint
                // column — v1 multiplies by 1048576 at the point of use.
                // Converting here would make every quota unlimited.
                'storage_quota_mb' => max(0, (int) ($row['max_disk_quota'] ?? 0)),

                'created_at' => $context->clock->toUtc($row['timestamp'] ?? null) ?? $now,
                'updated_at' => $now,
            ]);

            $existing[$key] = $id;
            $this->existingEmails[$key] = $id;

            $context->idMap->record(MigrationIdMap::ENTITY_USER, $sourceId, $id);
            $context->count($this->key(), $isClient ? 'clients' : 'staff');

            if ((int) ($row['notify'] ?? 0) === 0 && $isClient) {
                $context->count($this->key(), 'notifications_off_in_v1');
            }
        }
    }

    /**
     * @return array<int, bool>
     */
    private function clientRoles(MigrationContext $context): array
    {
        if ($this->clientRoles !== null) {
            return $this->clientRoles;
        }

        $roles = [];
        $afterId = 0;

        while (($rows = $context->source->rows(V1Tables::ROLES, $afterId, 200)) !== []) {
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                $roles[$afterId] = LegacyText::line((string) $row['name']) === 'Client';
            }
        }

        return $this->clientRoles = $roles;
    }

    /**
     * @return array<string, int>
     */
    private function existingEmails(): array
    {
        if ($this->existingEmails !== null) {
            return $this->existingEmails;
        }

        $emails = [];

        foreach (DB::table(HostTables::USERS)->select('id', 'email')->get() as $row) {
            $emails[mb_strtolower((string) $row->email)] = (int) $row->id;
        }

        return $this->existingEmails = $emails;
    }
}
