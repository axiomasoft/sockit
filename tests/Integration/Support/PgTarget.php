<?php

declare(strict_types=1);

namespace SocKit\Tests\Integration\Support;

use PDO;
use RuntimeException;

final class PgTarget
{
    /** @param array{run: string, container: string, host: string, port: int, owner: string, decoy: string, user: string, password: string} $manifest */
    public function __construct(private readonly array $manifest)
    {
        $run = $manifest['run'];
        if (! preg_match('/^[a-f0-9]{16}$/D', $run) || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['container']) || $manifest['host'] !== '127.0.0.1' || $manifest['port'] < 1 || $manifest['port'] > 65535 || $manifest['owner'] !== 'sockit_p2_'.$run.'_test' || $manifest['decoy'] !== 'sockit_p2_decoy_'.$run.'_test' || $manifest['user'] !== 'postgres') {
            throw new RuntimeException('Unsafe PostgreSQL test target');
        }
    }

    public static function fromEnvironment(): self
    {
        $path = getenv('SOCKIT_PG_MANIFEST');
        if ($path === false || ! is_file($path) || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Missing private PostgreSQL run manifest');
        }
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid run manifest');
        }
        foreach (['run', 'container', 'host', 'owner', 'decoy', 'user', 'password'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key])) {
                throw new RuntimeException('Invalid run manifest field');
            }
        }
        if (! isset($data['port']) || ! is_int($data['port'])) {
            throw new RuntimeException('Invalid run manifest port');
        }

        /** @var array{run: string, container: string, host: string, port: int, owner: string, decoy: string, user: string, password: string} $data */
        return new self($data);
    }

    public function connect(bool $decoy = false): PDO
    {
        $m = $this->manifest;
        $command = 'docker inspect --format '.escapeshellarg('{{index .Config.Labels "sockit.test.run"}} {{(index (index .NetworkSettings.Ports "5432/tcp") 0).HostIp}} {{(index (index .NetworkSettings.Ports "5432/tcp") 0).HostPort}}').' '.escapeshellarg($m['container']);
        exec($command, $lines, $exit);
        if ($exit !== 0 || implode("\n", $lines) !== $m['run'].' 127.0.0.1 '.$m['port']) {
            throw new RuntimeException('PostgreSQL container identity mismatch');
        }
        $database = $decoy ? $m['decoy'] : $m['owner'];
        $pdo = new PDO('pgsql:host=127.0.0.1;port='.$m['port'].';dbname='.$database, $m['user'], $m['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $row = self::query($pdo, "SELECT current_database() AS db, current_setting('server_version_num')::int AS version, shobj_description(oid, 'pg_database') AS marker FROM pg_database WHERE datname = current_database()")->fetch(PDO::FETCH_ASSOC);
        if (! is_array($row) || $row['db'] !== $database || (! is_numeric($row['version']) || (int) $row['version'] < 160000) || $row['marker'] !== 'sockit-p21-'.$m['run']) {
            throw new RuntimeException('PostgreSQL database identity mismatch');
        }

        fwrite(STDERR, 'PG identity database='.$database.' server_version_num='.(string) $row['version'].' marker='.(string) $row['marker'].' backend_pid='.(string) self::query($pdo, 'SELECT pg_backend_pid()')->fetchColumn().PHP_EOL);

        return $pdo;
    }

    /** @return array{driver: string, host: string, port: int, database: string, username: string, password: string} */
    public function decoyConfiguration(): array
    {
        // Verify the target before returning configuration for the isolated test manager.
        $this->connect(decoy: true);

        return [
            'driver' => 'pgsql', 'host' => $this->manifest['host'], 'port' => $this->manifest['port'],
            'database' => $this->manifest['decoy'], 'username' => $this->manifest['user'], 'password' => $this->manifest['password'],
        ];
    }

    public static function query(PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('PostgreSQL query failed');
        }

        return $statement;
    }
}
