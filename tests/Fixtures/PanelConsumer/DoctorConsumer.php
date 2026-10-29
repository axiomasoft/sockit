<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\PanelConsumer;

use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\Internal\SnapshotCatalog;
use SocKit\Tests\Fixtures\Schema\MigrationFixture;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

final readonly class DoctorConsumer
{
    public function __construct(public MigrationFixture $fixture) {}

    /** @param array<string, mixed> $options
     * @return array<string, mixed> */
    public function run(array $options = []): array
    {
        $before = $this->fixture->tree();
        $catalog = $this->state();
        $process = new Process([PHP_BINARY, __DIR__.'/doctor.php', $this->fixture->root, json_encode($options, JSON_THROW_ON_ERROR)]);
        $process->setTimeout(30);
        $process->mustRun();
        $result = Scenario::object(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        expect($result['in_transaction'])->toBeFalse()->and($this->fixture->tree())->toBe($before)->and($this->state())->toBe($catalog);
        if ($result['sql'] !== []) {
            expect(Scenario::entries($result['sql'])[0])->toBe('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        }

        return $result;
    }

    /** @param array<string, mixed> $result */
    public static function field(array $result, string|int ...$keys): mixed
    {
        $value = $result;
        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                throw new \RuntimeException('Missing doctor report field');
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** Small digests of catalog and data, no dumps.
     * @return array<string, string> */
    private function state(): array
    {
        $pdo = $this->fixture->pg->owner;
        $relations = PgTarget::query($pdo, "SELECT quote_ident(n.nspname)||'.'||quote_ident(c.relname) AS name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('panel_a','panel_b','public') AND c.relkind='r' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $result = [];
        foreach ($relations as $value) {
            $table = Scenario::text($value);
            $query = $pdo->prepare(SnapshotCatalog::SQL);
            $query->execute([$table]);
            $catalog = $query->fetch(\PDO::FETCH_ASSOC);
            $rows = PgTarget::query($pdo, 'SELECT md5(coalesce(string_agg(row_to_json(t)::text,\'\' ORDER BY row_to_json(t)::text),\'\')) FROM '.$table.' t')->fetchColumn();
            $result[$table] = hash('sha256', SchemaValues::json([$catalog, $rows]));
        }

        return $result;
    }
}
