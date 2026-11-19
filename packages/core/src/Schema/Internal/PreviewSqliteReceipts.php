<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use PDO;
use Pdo\Sqlite;
use SocKit\Internal\Generation\GenerationFailure;

/** The unpublished SQLite preview supported arbitrary physical table names. Inspect only column metadata. */
final class PreviewSqliteReceipts
{
    /** @param array<string, mixed> $connection */
    public static function check(array $connection, string $root): void
    {
        $database = $connection['database'] ?? null;
        if (($connection['driver'] ?? null) !== 'sqlite' || ! is_string($database) || $database === '' || $database === ':memory:') {
            return;
        }
        $file = realpath($database) ?: realpath($root.'/'.$database);
        if ($file === false || ! is_file($file)) {
            return;
        }
        $reader = new PDO('sqlite:'.$file, options: [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY]);
        $query = $reader->query(<<<'SQL'
            SELECT 1
            FROM sqlite_schema AS s JOIN pragma_table_info(s.name) AS c
            WHERE s.type = 'table' AND c.name IN (
                'panel_id', 'command_namespace', 'command_key', 'command_type', 'actor_kind',
                'payload_digest', 'applied_revision', 'result_payload'
            )
            GROUP BY s.name HAVING count(*) = 8 LIMIT 1
            SQL);
        if ($query !== false && $query->fetchColumn() !== false) {
            throw new GenerationFailure('MIGRATION_PREVIEW_SQLITE_RECEIPTS_INCOMPATIBLE', [
                'Keep the preview SQLite database and its journal files. Inspect or export it with sqlite3 -readonly.',
                'Initialize a separate PostgreSQL panel database. Preview receipts have no current replay authority evidence.',
            ]);
        }
    }
}
