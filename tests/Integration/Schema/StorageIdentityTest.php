<?php

declare(strict_types=1);

use Illuminate\Database\PostgresConnection;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\Schema\Internal\StorageDeployment;
use SocKit\Schema\Internal\StorageIdentityResolver;
use SocKit\Schema\Internal\StoragePreflight;
use SocKit\Tests\Fixtures\Schema\StorageFixtures;
use SocKit\Tests\Fixtures\Schema\StoragePgFixture;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('discovers actual catalog identity and refuses two connection names before DDL', function (): void {
    $fixture = new StoragePgFixture;
    $first = $fixture->connection('first');
    $alias = $fixture->connection('alias');
    expect($first->getName())->not->toBe($alias->getName());
    expect(PgTarget::query($first->getPdo(), 'SELECT pg_backend_pid()')->fetchColumn())->not->toBe(PgTarget::query($alias->getPdo(), 'SELECT pg_backend_pid()')->fetchColumn());
    $pdo = $fixture->owner;
    $before = PgTarget::query($pdo, 'SELECT oid, relnamespace, relname FROM pg_class ORDER BY oid')->fetchAll(PDO::FETCH_ASSOC);
    $pdo->beginTransaction();
    try {
        $pdo->exec('SET TRANSACTION READ ONLY');
        $preflight = new StoragePreflight;
        $a = $preflight->inspect(schema: StorageFixtures::schema(), connection: $first, deployment: $fixture->deployment);
        $b = $preflight->inspect(schema: StorageFixtures::schema('host.other'), connection: $alias, deployment: $fixture->deployment);
        $preflight->assertVacant(connection: $first, schema: $a);
        expect($a->location->schema)->toBe('public')->and($a->location->key())->toBe($b->location->key());
        expect(fn () => $preflight->assertDisjoint([$a, $b]))->toThrow(InvalidSchemaException::class);
        expect(PgTarget::query($pdo, 'SHOW transaction_read_only')->fetchColumn())->toBe('on')
            ->and(PgTarget::query($pdo, 'SELECT oid, relnamespace, relname FROM pg_class ORDER BY oid')->fetchAll(PDO::FETCH_ASSOC))->toBe($before);
        fwrite(STDERR, 'SQL assertions: aliases=first,alias distinct_pdo_backends=true same_physical_key=true collision_before_ddl=true transaction_read_only=on catalog_unchanged=true'.PHP_EOL);
    } finally {
        $pdo->rollBack();
    }
});

it('freezes the resolved search_path schema and checks real final table index and FK names', function (): void {
    $fixture = new StoragePgFixture;
    $pdo = $fixture->owner;
    $decoy = $fixture->decoy;
    $pdo->beginTransaction();
    $decoy->beginTransaction();
    try {
        $pdo->exec('CREATE SCHEMA p32_owned');
        $pdo->exec('CREATE SCHEMA p32_neighbor');
        $pdo->exec('CREATE TABLE p32_neighbor.sentinel (value text)');
        $pdo->exec("INSERT INTO p32_neighbor.sentinel VALUES ('untouched')");
        $decoy->exec('CREATE TABLE public.p32_sentinel (value text)');
        $decoy->exec("INSERT INTO public.p32_sentinel VALUES ('untouched')");
        $pdo->exec('SET LOCAL search_path TO p32_owned, public');
        $connection = $fixture->connection();
        $preflight = new StoragePreflight;
        $snapshot = $preflight->inspect(schema: StorageFixtures::schema(), connection: $connection, deployment: $fixture->deployment);
        expect($snapshot->location->schema)->toBe('p32_owned');
        $before = $snapshot->toArray();
        $pdo->exec('SET LOCAL search_path TO p32_neighbor, public');
        expect($snapshot->toArray())->toBe($before);
        $preflight->assertVacant(connection: $connection, schema: $snapshot);
        $entities = Scenario::object($before['entities']);
        $target = Scenario::object($entities['demo_targets']);
        $link = Scenario::object($entities['demo_links']);
        $targetTable = Scenario::text($target['table']);
        $linkTable = Scenario::text($link['table']);
        $targetPk = Scenario::text(Scenario::object($target['primary'])['name']);
        $linkPk = Scenario::text(Scenario::object($link['primary'])['name']);
        $index = Scenario::text(Scenario::object(Scenario::object($target['indexes'])['external'])['name']);
        $fk = Scenario::object(Scenario::object($link['foreign_keys'])['target']);
        $foreignKey = Scenario::text($fk['name']);
        expect($targetTable)->toBe('drv_articles_demo_targets')->and($fk['table'])->toBe($targetTable)->and($fk['schema'])->toBe('p32_owned');
        $q = StoragePgFixture::quote(...);
        // Minimal explicit fixture DDL, not a production migration/helper implementation.
        $pdo->exec('CREATE TABLE p32_owned.'.$q($targetTable).' (id uuid, installation text, panel text, external_key text, CONSTRAINT '.$q($targetPk).' PRIMARY KEY (id))');
        $pdo->exec('CREATE UNIQUE INDEX '.$q($index).' ON p32_owned.'.$q($targetTable).' (installation, panel, external_key)');
        $pdo->exec('CREATE TABLE p32_owned.'.$q($linkTable).' (id uuid, target_id uuid, CONSTRAINT '.$q($linkPk).' PRIMARY KEY (id), CONSTRAINT '.$q($foreignKey).' FOREIGN KEY (target_id) REFERENCES p32_owned.'.$q($targetTable).' (id))');
        $relations = PgTarget::query($pdo, "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='p32_owned' ORDER BY c.relname")->fetchAll(PDO::FETCH_COLUMN);
        $expected = [$targetTable, $linkTable, $targetPk, $linkPk, $index];
        sort($expected);
        expect($relations)->toBe($expected);
        $statement = $pdo->prepare("SELECT conname FROM pg_constraint WHERE connamespace='p32_owned'::regnamespace AND contype='f'");
        $statement->execute();
        expect($statement->fetchColumn())->toBe($foreignKey);
        expect(fn () => $preflight->assertVacant(connection: $connection, schema: $snapshot))->toThrow(InvalidSchemaException::class);
        expect(PgTarget::query($pdo, 'SELECT value FROM p32_neighbor.sentinel')->fetchColumn())->toBe('untouched')
            ->and(PgTarget::query($decoy, 'SELECT value FROM public.p32_sentinel')->fetchColumn())->toBe('untouched');
        fwrite(STDERR, 'SQL assertions: frozen_schema=p32_owned search_path_changed=true exact_relations='.json_encode($relations, JSON_THROW_ON_ERROR).' exact_fk='.$foreignKey.' existing_relation_rejected=true neighbor=untouched decoy=untouched'.PHP_EOL);
    } finally {
        $pdo->rollBack();
        $decoy->rollBack();
    }
    expect(PgTarget::query($pdo, "SELECT to_regnamespace('p32_owned')")->fetchColumn())->toBeNull();
});

it('accepts 63 byte final identifiers and rejects 64 bytes before catalog changes', function (): void {
    $fixture = new StoragePgFixture;
    $pdo = $fixture->owner;
    $pdo->beginTransaction();
    try {
        $schemaName = str_repeat('s', 63);
        $columnName = str_repeat('c', 63);
        $q = StoragePgFixture::quote(...);
        $pdo->exec('CREATE SCHEMA '.$q($schemaName));
        $location = new StorageIdentityResolver()->discover(connection: $fixture->connection(), deployment: $fixture->deployment, schema: $schemaName);
        $preflight = new StoragePreflight;
        $draft = StorageFixtures::schema(tables: ['demo_targets' => str_repeat('t', 60)]);
        $snapshot = $preflight->compile(schema: $draft, location: $location, driverPrefix: 'db_');
        $target = Scenario::object(Scenario::object($snapshot->toArray()['entities'])['demo_targets']);
        $table = Scenario::text($target['table']);
        $primary = Scenario::text(Scenario::object($target['primary'])['name']);
        $pdo->exec('CREATE TABLE '.$q($schemaName).'.'.$q($table).' ('.$q($columnName).' uuid, CONSTRAINT '.$q($primary).' PRIMARY KEY ('.$q($columnName).'))');
        $catalog = PgTarget::query($pdo, "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='".$schemaName."' ORDER BY c.relname")->fetchAll(PDO::FETCH_COLUMN);
        $expected = [$table, $primary];
        sort($expected);
        expect(strlen($table))->toBe(63)->and($catalog)->toBe($expected);
        $columns = PgTarget::query($pdo, "SELECT a.attname FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='".$schemaName."' AND a.attnum>0 AND NOT a.attisdropped")->fetchAll(PDO::FETCH_COLUMN);
        expect($columns)->toContain($columnName);
        expect(fn () => $preflight->compile(schema: $draft, location: $location, driverPrefix: 'dbb_'))->toThrow(InvalidSchemaException::class);
        expect(PgTarget::query($pdo, "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='".$schemaName."' ORDER BY c.relname")->fetchAll(PDO::FETCH_COLUMN))->toBe($catalog);
        fwrite(STDERR, 'SQL assertions: schema_bytes=63 table_bytes=63 column_bytes=63 table_and_pk_exact=true overflow_64_rejected_before_ddl=true'.PHP_EOL);
    } finally {
        $pdo->rollBack();
    }
});

it('fails closed on live database endpoint and schema disagreement', function (): void {
    $fixture = new StoragePgFixture;
    $connection = $fixture->connection();
    $resolver = new StorageIdentityResolver;
    $config = Scenario::object($connection->getConfig());
    $wrongDb = new PostgresConnection(pdo: $fixture->owner, config: [...$config, 'database' => 'wrong_test']);
    expect(fn () => $resolver->discover(connection: $wrongDb, deployment: $fixture->deployment, schema: 'public'))->toThrow(InvalidSchemaException::class)
        ->and(fn () => $resolver->discover(connection: $connection, deployment: $fixture->deployment, schema: 'p32_nonexistent'))->toThrow(InvalidSchemaException::class);
    $noObservedEndpoint = StorageDeployment::fromArray(['cluster' => 'owned_pg', 'endpoint' => ['host' => $config['host'], 'port' => $config['port']]]);
    expect(fn () => $resolver->discover(connection: $connection, deployment: $noObservedEndpoint, schema: 'public'))->toThrow(InvalidSchemaException::class);
    $fixture->owner->beginTransaction();
    try {
        $fixture->owner->exec('SET LOCAL search_path TO p32_nonexistent');
        expect(fn () => $resolver->discover(connection: $connection, deployment: $fixture->deployment, schema: null))->toThrow(InvalidSchemaException::class);
    } finally {
        $fixture->owner->rollBack();
    }
    fwrite(STDERR, 'SQL assertions: database_mismatch=denied endpoint_mismatch=denied missing_schema=denied unresolved_search_path=denied'.PHP_EOL);
});
