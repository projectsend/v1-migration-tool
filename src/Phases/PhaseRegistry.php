<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Phases;

/**
 * The order phases run in, and the reason for it.
 *
 * Derived from v1's own foreign-key graph — it is the reverse of the
 * delete order v1 uses in `lib/Reset.php`, which is the same statement
 * read from the other end. Nothing here is arbitrary:
 *
 * settings first, because nothing depends on it and getting it wrong is
 * cheap to notice. Then roles, because accounts point at them. Then
 * accounts, because everything else points at accounts. Groups before
 * their members; categories and folders before files, because a file
 * names both; files before anything that references a file.
 *
 * History is last on purpose. It is the largest phase by an order of
 * magnitude, it references files and accounts that must already exist,
 * and it is the one phase an operator might reasonably abandon
 * halfway — at which point everything that matters is already in.
 */
final class PhaseRegistry
{
    /**
     * @return list<Phase>
     */
    public static function all(): array
    {
        return [
            new SettingsPhase,
            new MailSettingsPhase,
            new RolesPhase,
            new RolePermissionsPhase,
            new UsersPhase,
            new StaffClientAssignmentsPhase,
            new GroupsPhase,
            new GroupMembersPhase,
            new MembershipRequestsPhase,
            new CategoriesPhase,
            new FoldersPhase,
            new CustomFieldsPhase,
            new CustomFieldValuesPhase,
            new FilesPhase,
            new FileAssignmentsPhase,
            new FileCategoriesPhase,
            new DownloadsPhase,
            new ActivityLogPhase,
            new FinalisePhase,
        ];
    }
}
