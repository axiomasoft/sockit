<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Operations\Internal\Storage\SurfaceStateException;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Enums\SurfaceLifecycle;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;
use SocKit\Tests\Fixtures\Runtime\SurfaceScenario;

$app = Runtime::app();
Runtime::trust($app);
$db = new RuntimeDatabase($app); // verifies private manifest/identity before opening owner transaction
$connection = $db->capsule->getConnection('owner_a');
$row = $connection->selectOne('SELECT pg_backend_pid() AS pid');
if (! $row instanceof stdClass || ! isset($row->pid) || ! is_int($row->pid)) {
    throw new RuntimeException('No child PID');
}
fwrite(STDOUT, $row->pid.PHP_EOL);
fflush(STDOUT);
try {
    SurfaceScenario::owner($app)->transaction(function (OwnerConnection $owner) use ($argv): void {
        $instances = new SurfaceInstances($owner);
        if ($argv[2] === 'hold') {
            if ($argv[1] === 'create') {
                $instances->create(sourceGeneration: new Revision(1), privacyGeneration: new Revision(1));
            } else {
                $instances->lock();
            }
            fwrite(STDOUT, "locked\n");
            fflush(STDOUT);
            stream_set_timeout(STDIN, 10);
            if (trim((string) fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Barrier release missing');
            }
        }
        match ($argv[1]) {
            'create' => $argv[2] === 'hold' ? null : $instances->create(sourceGeneration: new Revision(1), privacyGeneration: new Revision(1)),
            'archive' => $instances->transition(to: SurfaceLifecycle::Archived, expectedRevision: new Revision(1), sourceGeneration: new Revision(1), privacyGeneration: new Revision(1)),
            'delete' => $instances->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(1), sourceGeneration: new Revision(2), privacyGeneration: new Revision(2)),
            'edit' => $instances->revise(expectedRevision: new Revision(1), sourceGeneration: new Revision(1), privacyGeneration: new Revision(2)),
            'write' => $instances->writable(new Revision(1)),
            'replay' => $instances->lock()->lifecycle->canRead() ? null : throw new SurfaceStateException(ProblemCode::ContentDeleted),
            default => throw new RuntimeException('Invalid action'),
        };
    });
    fwrite(STDOUT, "committed\n");
} catch (SurfaceStateException $error) {
    fwrite(STDOUT, $error->problem()->value.PHP_EOL);
}
