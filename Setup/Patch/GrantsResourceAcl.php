<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Setup\Patch;

/**
 * Shared by the data patches that hand an existing admin role an ACL resource added after that
 * role was last saved. Generic copy of releaserental2's GrantsRentalResourceAcl (1.2.57): the
 * using class passes which resources make a role "ours" (a SQL LIKE pattern) to
 * grantResourceToRolesAllowing(). releaserental2 keeps its own trait unchanged.
 *
 * THE PROBLEM THIS EXISTS FOR REPEATS ON EVERY NEW SCREEN. Magento denies any resource a
 * role does not explicitly allow, and a role's rule set is written once, when the role is
 * saved. So a merchant on a custom admin role upgrades, a new menu item is simply not
 * there, and nothing anywhere says why -- it reads as a broken upgrade rather than as a
 * permission. Re-saving the role is the only cure, and only if you already know that is
 * the cure. Confirmed on the Magento demo for Rentals > Reminders: role "Demo" had 417
 * explicit rules and none of them was the new resource.
 *
 * The grant is deliberately narrow, and each exclusion is a decision:
 *
 *  - a role with only *deny* rows on rental resources stays denied. Somebody chose that.
 *  - a role with no rental rows at all is left alone. It never had our screens.
 *  - a `Magento_Backend::all` role needs no row; the ACL grants it everything already.
 *
 * Idempotent: a role that already carries a row for the resource -- allow or deny -- is
 * skipped, so re-running can never overwrite a deny somebody set on purpose afterwards.
 */
trait GrantsResourceAcl
{
    /**
     * Roles that allow something of ours and have no row for $resource yet.
     *
     * Pure, and separate from the database work, so it can be unit tested against a plain
     * array of rows rather than a connection.
     *
     * @param array  $rows     each ['role_id' => int, 'resource_id' => string, 'permission' => string]
     * @param string $resource the resource being granted
     * @return int[] role ids
     */
    public static function rolesToGrantFor(array $rows, string $resource): array
    {
        $allowsOurs = [];
        $hasResource = [];
        foreach ($rows as $row) {
            $roleId = (int)$row['role_id'];
            if ($row['resource_id'] === $resource) {
                $hasResource[$roleId] = true;
                continue;
            }
            if ($row['permission'] === 'allow') {
                $allowsOurs[$roleId] = true;
            }
        }
        return array_values(array_diff(array_keys($allowsOurs), array_keys($hasResource)));
    }

    /**
     * Grant $resourceId to every role that allows something matching $ownedPattern (a SQL LIKE
     * pattern, e.g. 'SalesIgniter_Rfq::%', or an exact id such as 'Magento_Sales::sales') and has no
     * row for $resourceId yet. Rows for $resourceId are read whether or not they match the pattern,
     * so a deny set on purpose, or an earlier grant, is never duplicated or overwritten.
     *
     * @return int[] the role ids granted, for the caller to log
     */
    private function grantResourceToRolesAllowing(
        \Magento\Framework\App\ResourceConnection $resource,
        string $resourceId,
        string $ownedPattern
    ): array {
        $connection = $resource->getConnection();
        $table = $resource->getTableName('authorization_rule');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['role_id', 'resource_id', 'permission'])
                ->where('resource_id LIKE ?', $ownedPattern)
                ->orWhere('resource_id = ?', $resourceId)
        );

        $roleIds = self::rolesToGrantFor($rows, $resourceId);
        if (!$roleIds) {
            return [];
        }

        $insert = [];
        foreach ($roleIds as $roleId) {
            $insert[] = [
                'role_id' => $roleId,
                'resource_id' => $resourceId,
                'privileges' => null,
                'permission' => 'allow',
            ];
        }
        $connection->insertMultiple($table, $insert);

        return $roleIds;
    }
}
