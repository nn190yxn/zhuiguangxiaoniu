<?php
declare(strict_types=1);

final class EmployeeKnowledgeVisibilityQuery
{
    public static function fromCurrentVersion(string $itemAlias = 'k', string $versionAlias = 'kv'): string
    {
        self::assertAlias($itemAlias);
        self::assertAlias($versionAlias);
        if ($itemAlias === $versionAlias) {
            throw new InvalidArgumentException('Knowledge item and version aliases must be different');
        }

        return 'knowledge_items ' . $itemAlias
            . ' INNER JOIN knowledge_item_versions ' . $versionAlias
            . ' ON ' . $versionAlias . '.version_id = ' . $itemAlias . '.current_version_id'
            . ' AND ' . $versionAlias . '.knowledge_item_id = ' . $itemAlias . '.id'
            . " AND " . $versionAlias . ".status = 'active'"
            . ' AND ' . $itemAlias . '.status = 1'
            . " AND " . $itemAlias . ".publication_status = 'published'";
    }

    private static function assertAlias(string $alias): void
    {
        if (strlen($alias) > 64 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias) !== 1) {
            throw new InvalidArgumentException('Invalid SQL alias');
        }
    }
}
