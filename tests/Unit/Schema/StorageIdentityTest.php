<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\Assert;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\Internal\StorageDeployment;
use SocKit\Schema\Internal\StorageEndpoint;
use SocKit\Schema\Internal\StorageIdentityResolver;
use SocKit\Schema\Internal\StoragePreflight;
use SocKit\Schema\PanelSchema;
use SocKit\Tests\Fixtures\Schema\StorageFixtures;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('collapses declared same-server connection aliases before any database access', function (): void {
    $resolver = new StorageIdentityResolver;
    $a = $resolver->declared(connection: StorageFixtures::connection(), deployment: StorageFixtures::deployment(), schema: 'public');
    $b = $resolver->declared(connection: StorageFixtures::connection('alias.internal'), deployment: StorageFixtures::deployment(), schema: 'public');
    expect($a->key())->toBe($b->key());
    $preflight = new StoragePreflight;
    $one = $preflight->compile(schema: StorageFixtures::schema(), location: $a, driverPrefix: 'driver_');
    $two = $preflight->compile(schema: StorageFixtures::schema('host.other'), location: $b, driverPrefix: 'driver_');
    expect(fn () => $preflight->assertDisjoint([$one, $two]))->toThrow(InvalidSchemaException::class);
});

it('keeps proven distinct servers databases and schemas independent', function (): void {
    $resolver = new StorageIdentityResolver;
    $a = $resolver->declared(connection: StorageFixtures::connection(), deployment: StorageFixtures::deployment(), schema: 'public');
    $b = $resolver->declared(connection: StorageFixtures::connection('other.internal'), deployment: StorageFixtures::deployment('cluster_b', 'other.internal'), schema: 'public');
    $c = $resolver->declared(connection: StorageFixtures::connection(), deployment: StorageFixtures::deployment(), schema: 'other');
    $d = $resolver->declared(connection: array_replace(StorageFixtures::connection(), ['database' => 'other_test']), deployment: StorageFixtures::deployment(), schema: 'public');
    $preflight = new StoragePreflight;
    // Distinct servers are trusted descriptor fixtures, not multi-server e2e.
    $schemas = array_map(fn ($location) => $preflight->compile(schema: StorageFixtures::schema(), location: $location, driverPrefix: ''), [$a, $b, $c, $d]);
    $preflight->assertDisjoint($schemas);
    expect(array_unique(array_map(fn ($schema): string => $schema->location->key(), $schemas)))->toHaveCount(4);
});

it('normalizes spelling without guessing DNS or socket equivalence', function (): void {
    expect(StorageEndpoint::normalize('DB.INTERNAL.', '5432'))->toBe(StorageEndpoint::normalize('db.internal'))
        ->and(StorageEndpoint::normalize('2001:0db8:0:0::1'))->toBe('tcp:[2001:db8::1]:5432')
        ->and(StorageEndpoint::normalize('/var//run/./postgresql/'))->toBe('unix:/var/run/postgresql:5432')
        ->and(StorageEndpoint::normalize('/var/run/postgresql', 5433))->not->toBe(StorageEndpoint::normalize('/var/run/postgresql'))
        ->and(StorageEndpoint::normalize('localhost'))->not->toBe(StorageEndpoint::normalize('127.0.0.1'));
    $deployment = StorageDeployment::fromArray(['cluster' => 'socket_a', 'endpoint' => ['host' => '/var/run/postgresql'], 'aliases' => [['host' => '/run/postgresql']]]);
    $a = new StorageIdentityResolver()->declared(connection: StorageFixtures::connection('/run/postgresql'), deployment: $deployment, schema: 'public');
    $b = new StorageIdentityResolver()->declared(connection: StorageFixtures::connection('/var//run/postgresql/'), deployment: $deployment, schema: 'public');
    expect($a->key())->toBe($b->key());
});

it('rejects ambiguous unsafe or incomplete endpoints', function (mixed $host, mixed $port): void {
    expect(fn () => StorageEndpoint::normalize($host, $port))->toThrow(InvalidSchemaException::class);
})->with([
    'absent' => [null, 5432], 'list' => [['db', 'standby'], 5432], 'multi' => ['db,standby', 5432],
    'dsn' => ['postgres://secret:password@db', 5432], 'socket parent' => ['/run/../postgresql', 5432],
    'socket abstract' => ['@postgresql', 5432], 'port zero' => ['db', 0], 'port overflow' => ['db', 65536],
    'port injection' => ['db', '5432;password=secret'], 'whitespace' => [' db ', 5432], 'empty port' => ['db', ''],
    'empty label' => ['db..internal', 5432], 'unsafe' => ['db/other', 5432],
]);

it('requires a descriptor match and a resolved schema without attempting discovery', function (array $change, ?string $schema): void {
    expect(fn () => new StorageIdentityResolver()->declared(connection: array_replace(StorageFixtures::connection(), Scenario::object($change)), deployment: StorageFixtures::deployment(), schema: $schema))->toThrow(InvalidSchemaException::class);
})->with([
    'proxy unknown' => [['host' => 'proxy.internal'], 'public'],
    'socket unknown' => [['host' => '/tmp/postgresql'], 'public'],
    'schema unresolved' => [[], null], 'schema search path' => [[], 'first,public'],
    'schema quoted' => [[], 'Public'], 'schema unicode' => [[], 'схема'], 'schema overflow' => [[], str_repeat('s', 64)],
    'driver' => [['driver' => 'mysql'], 'public'], 'database missing' => [['database' => null], 'public'],
    'database dsn' => [['database' => 'db;password=secret'], 'public'],
    'read replica' => [['read' => ['host' => 'replica']], 'public'],
    'write routing' => [['write' => ['host' => 'primary']], 'public'],
    'dsn override' => [['url' => 'pgsql://user:secret@proxy/db'], 'public'],
]);

it('rejects contradictory endpoint cluster and alias assignments', function (): void {
    expect(fn () => StorageDeployment::assertConsistent([StorageFixtures::deployment(), StorageFixtures::deployment('cluster_b')]))->toThrow(InvalidSchemaException::class)
        ->and(fn () => StorageDeployment::assertConsistent([StorageFixtures::deployment(), StorageFixtures::deployment('cluster_a', 'other.internal')]))->toThrow(InvalidSchemaException::class)
        ->and(fn () => StorageDeployment::fromArray(['cluster' => 'a', 'endpoint' => ['host' => 'db'], 'password' => 'never-log']))->toThrow(InvalidSchemaException::class);
});

it('validates config-safe descriptors at normalization without connection resolution', function (): void {
    $descriptor = ['cluster' => 'a', 'endpoint' => ['host' => 'DB.INTERNAL.', 'port' => 5432], 'aliases' => [['host' => 'alias.internal']]];
    $config = SocKitConfig::normalize(new Repository(['sockit' => ['storage' => ['deployments' => ['social' => $descriptor]]]]));
    $restored = unserialize(serialize($config), ['allowed_classes' => false]);
    expect($restored)->toBe($config)->and(SocKitConfig::normalize(new Repository)['storage'])->toBe(['deployments' => []]);
    expect(fn () => SocKitConfig::normalize(new Repository(['sockit' => ['storage' => ['deployments' => ['social' => ['cluster' => 'a', 'endpoint' => ['host' => 'db'], 'password' => 'secret']]]]])))->toThrow(InvalidConfigurationException::class);
});

it('omits credentials from descriptors digests and failure diagnostics', function (): void {
    $resolver = new StorageIdentityResolver;
    $a = $resolver->declared(connection: StorageFixtures::connection(), deployment: StorageFixtures::deployment(), schema: 'public');
    $b = $resolver->declared(connection: array_replace(StorageFixtures::connection(), ['username' => 'private_user', 'password' => 'private_password', 'options' => ['private_option']]), deployment: StorageFixtures::deployment(), schema: 'public');
    expect($a->key())->toBe($b->key());
    $snapshot = new StoragePreflight()->compile(schema: StorageFixtures::schema(), location: $b, driverPrefix: '');
    expect(SchemaValues::json($snapshot->toArray()))->not->toContain('private_', 'never-in-identity', 'password', 'username', 'options');
    try {
        $resolver->declared(connection: array_replace(StorageFixtures::connection(), ['host' => 'postgres://private_user:private_password@db']), deployment: StorageFixtures::deployment(), schema: 'public');
        Assert::fail('Secret-bearing invalid endpoint was admitted');
    } catch (InvalidSchemaException $error) {
        expect($error->getPrevious())->toBeNull()
            ->and($error->getMessage().SchemaValues::json($error->detail()))->not->toContain('private_', 'postgres://');
    }
});

it('redacts PDO failures and never tries a connection from the pure path', function (): void {
    $calls = 0;
    $connection = new PostgresConnection(pdo: function () use (&$calls): PDO {
        $calls++;
        throw new PDOException('private_password in failed DSN');
    }, config: StorageFixtures::connection());
    $resolver = new StorageIdentityResolver;
    $resolver->declared(connection: StorageFixtures::connection(), deployment: StorageFixtures::deployment(), schema: 'public');
    expect($calls)->toBe(0);
    try {
        $resolver->discover(connection: $connection, deployment: StorageFixtures::deployment(), schema: 'public');
        Assert::fail('PDO failure was admitted');
    } catch (InvalidSchemaException $error) {
        expect($calls)->toBe(1)->and($error->getPrevious())->toBeNull()
            ->and($error->detail()['rule'])->toBe('STORAGE_DISCOVERY_FAILED')
            ->and($error->getMessage().SchemaValues::json($error->detail()))->not->toContain('private_password', 'DSN');
    }
});

it('detects physical collisions from different panel prefixes and custom overrides', function (): void {
    $location = new StorageIdentityResolver()->declared(connection: StorageFixtures::connection(), deployment: StorageFixtures::deployment(), schema: 'public');
    $preflight = new StoragePreflight;
    $first = $preflight->compile(schema: StorageFixtures::schema(), location: $location, driverPrefix: '');
    $second = $preflight->compile(schema: StorageFixtures::schema('host.other', 'other_', ['demo_targets' => 'articles_receipts']), location: $location, driverPrefix: '');
    expect(fn () => $preflight->assertDisjoint([$first, $second]))->toThrow(InvalidSchemaException::class);
    $independent = $preflight->compile(schema: StorageFixtures::schema('host.other', 'other_'), location: $location, driverPrefix: '');
    $preflight->assertDisjoint([$first, $independent]);
    expect(array_intersect($first->relations, $independent->relations))->toBe([]);
});

it('validates final driver prefix plus panel prefix suffix and table overrides', function (): void {
    $location = new StorageIdentityResolver()->declared(connection: StorageFixtures::connection(), deployment: StorageFixtures::deployment(), schema: 'public');
    $preflight = new StoragePreflight;
    $schema = StorageFixtures::schema(tables: ['demo_targets' => str_repeat('t', 60)]);
    $physical = $preflight->compile(schema: $schema, location: $location, driverPrefix: 'db_')->toArray();
    expect(Scenario::object($physical['tables'])['demo_targets'])->toBe('db_'.str_repeat('t', 60));
    expect(fn () => $preflight->compile(schema: $schema, location: $location, driverPrefix: 'dbb_'))->toThrow(InvalidSchemaException::class);
    $longPanelPrefix = StorageFixtures::schema(prefix: str_repeat('p', 44).'_');
    expect(fn () => $preflight->compile(schema: $longPanelPrefix, location: $location, driverPrefix: str_repeat('d', 10).'_'))->toThrow(InvalidSchemaException::class);
    foreach (['Prefix_', 'м_', 'bad-', str_repeat('p', 64)] as $prefix) {
        expect(fn () => $preflight->compile(schema: $schema, location: $location, driverPrefix: $prefix))->toThrow(InvalidSchemaException::class);
    }
});

it('validates physical column boundaries without truncating or sanitizing', function (string $column, bool $valid): void {
    $schema = StorageFixtures::schema();
    $wire = $schema->jsonSerialize();
    $entities = Scenario::object($wire['entities']);
    $target = Scenario::object($entities['demo_targets']);
    $target['columns'] = [...Scenario::object($target['columns']), $column => ['type' => 'text']];
    $entities['demo_targets'] = $target;
    $modified = new PanelSchema(panel: $schema->panel, storage: $schema->storage, components: Scenario::object($wire['components']), entities: $entities, order: array_map(Scenario::text(...), Scenario::entries($wire['dependency_order'])));
    $location = new StorageIdentityResolver()->declared(connection: StorageFixtures::connection(), deployment: StorageFixtures::deployment(), schema: 'public');
    if ($valid) {
        $snapshot = new StoragePreflight()->compile(schema: $modified, location: $location, driverPrefix: '');
        expect(SchemaValues::json($snapshot->toArray()))->toContain($column);
    } else {
        expect(fn () => new StoragePreflight()->compile(schema: $modified, location: $location, driverPrefix: ''))->toThrow(InvalidSchemaException::class);
    }
})->with([[str_repeat('c', 63), true], [str_repeat('c', 64), false], ['Column', false], ['колонка', false], ['a-b', false]]);

it('derives stable short names from full physical identity and detects relation name collisions', function (): void {
    $location = new StorageIdentityResolver()->declared(connection: StorageFixtures::connection(), deployment: StorageFixtures::deployment(), schema: str_repeat('s', 63));
    $preflight = new StoragePreflight;
    $a = $preflight->compile(schema: StorageFixtures::schema(), location: $location, driverPrefix: 'db_');
    $b = $preflight->compile(schema: StorageFixtures::schema(), location: $location, driverPrefix: 'db_');
    expect($a->digest)->toBe($b->digest);
    $names = [];
    foreach (Scenario::object($a->toArray()['entities']) as $entity) {
        $entity = Scenario::object($entity);
        foreach (['primary', 'indexes', 'foreign_keys', 'checks'] as $section) {
            $keys = $section === 'primary' ? [$entity[$section]] : Scenario::object($entity[$section]);
            foreach ($keys as $key) {
                $name = Scenario::text(Scenario::object($key)['name']);
                expect(strlen($name))->toBeLessThanOrEqual(63)->and($name)->toMatch('/^sk_(pk|idx|fk|ck)_[a-f0-9]{32}$/');
                $names[] = $name;
            }
        }
    }
    expect(array_unique($names))->toHaveCount(count($names));
    $changed = $preflight->compile(schema: StorageFixtures::schema(), location: $location, driverPrefix: 'other_');
    expect(array_intersect($a->relations, $changed->relations))->toBe([]);
    $entities = Scenario::object($a->toArray()['entities']);
    $receipts = Scenario::object($entities['receipts']);
    $collision = Scenario::text(Scenario::object($receipts['primary'])['name']);
    expect(fn () => $preflight->compile(schema: StorageFixtures::schema(tables: ['demo_targets' => $collision]), location: $location, driverPrefix: ''))->not->toThrow(InvalidSchemaException::class);
    // Derive using the SAME prefix as the colliding physical table.
    $plain = $preflight->compile(schema: StorageFixtures::schema(), location: $location, driverPrefix: '');
    $receipts = Scenario::object(Scenario::object($plain->toArray()['entities'])['receipts']);
    $collision = Scenario::text(Scenario::object($receipts['primary'])['name']);
    expect(fn () => $preflight->compile(schema: StorageFixtures::schema(tables: ['demo_targets' => $collision]), location: $location, driverPrefix: ''))->toThrow(InvalidSchemaException::class);
});
