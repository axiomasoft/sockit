<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use Packman\Attributes\Internal;
use PDO;
use SocKit\Internal\Generation\GenerationFailure;

#[Internal]
final class SnapshotCatalog
{
    public const string SQL = <<<'SQL'
SELECT c.oid::text AS oid, obj_description(c.oid, 'pg_class') AS receipt,
md5(jsonb_build_object(
    'relation', jsonb_build_array(c.relkind, c.relpersistence, c.relowner, c.relam, c.reltablespace, c.relispartition, c.relrowsecurity, c.relforcerowsecurity, c.reloptions),
    'columns', (SELECT jsonb_agg(jsonb_build_array(a.attnum, a.attname, a.atttypid, a.atttypmod, a.attcollation, a.attnotnull, a.attidentity, a.attgenerated, a.attisdropped, pg_get_expr(d.adbin, d.adrelid)) ORDER BY a.attnum) FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid=c.oid AND a.attnum>0),
    'constraints', (SELECT jsonb_agg(jsonb_build_array(k.oid, k.conname, k.contype, k.convalidated, k.condeferrable, k.condeferred, pg_get_constraintdef(k.oid)) ORDER BY k.conname) FROM pg_constraint k WHERE k.conrelid=c.oid),
    'indexes', (SELECT jsonb_agg(jsonb_build_array(i.indexrelid, i.indisvalid, i.indisready, pg_get_indexdef(i.indexrelid)) ORDER BY i.indexrelid) FROM pg_index i WHERE i.indrelid=c.oid),
    'triggers', (SELECT jsonb_agg(jsonb_build_array(t.oid, t.tgname, pg_get_triggerdef(t.oid), t.tgenabled) ORDER BY t.oid) FROM pg_trigger t WHERE t.tgrelid=c.oid AND NOT t.tgisinternal),
    'policies', (SELECT jsonb_agg(to_jsonb(p) ORDER BY p.oid) FROM pg_policy p WHERE p.polrelid=c.oid),
    'automatic_dependents', (SELECT jsonb_agg(jsonb_build_array(d.classid, d.objid, d.objsubid, d.refobjsubid, d.deptype) ORDER BY d.classid, d.objid, d.objsubid, d.refobjsubid, d.deptype) FROM pg_depend d WHERE d.refclassid='pg_class'::regclass AND d.refobjid=c.oid AND d.deptype IN ('a','i')),
    'rules', (SELECT jsonb_agg(jsonb_build_array(r.oid, pg_get_ruledef(r.oid)) ORDER BY r.oid) FROM pg_rewrite r WHERE r.ev_class=c.oid)
)::text) AS signature
FROM pg_class c WHERE c.oid = to_regclass(?) AND c.relkind = 'r'
SQL;

    /** @param list<string> $tables */
    public static function verify(PDO $pdo, array $tables, string $digest): void
    {
        $query = $pdo->query('SHOW search_path');
        GenerationFailure::require(valid: $query !== false, rule: 'MIGRATION_CATALOG_UNAVAILABLE');
        $previous = $query->fetchColumn();
        $set = $pdo->prepare("SELECT set_config('search_path', ?, false)");
        $set->execute(['pg_catalog']);
        try {
            $query = $pdo->prepare(self::SQL);
            foreach ($tables as $table) {
                $query->execute([$table]);
                $state = $query->fetch(PDO::FETCH_ASSOC);
                GenerationFailure::require(valid: is_array($state) && ($state['receipt'] ?? null) === 'sockit.snapshot.v1:'.$digest.':'.SchemaValues::text($state['oid']).':'.SchemaValues::text($state['signature']), rule: 'MIGRATION_APPLIED_OWNERSHIP_CHANGED');
            }
        } finally {
            $set->execute([$previous]);
        }
    }
}
