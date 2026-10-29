<?php

declare(strict_types=1);

use Acme\Groups\Host;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\GroupRef;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\Data\PanelId;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Posts;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Reactions\Reactions;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid application');
}
$app->make(Kernel::class)->bootstrap();
$app->instance(Host::class, new Host);
$connection = $app->make(DatabaseManager::class)->connection('owner_a');
$connection->statement("SET statement_timeout='10s'");
$surface = new SurfaceRef(new PanelId('acme.article'), new SurfaceKindId('acme.content'), new HostEntityRef('sockit.group', $argv[1]), new Incarnation(1));
$h = $app->make(OperationHandles::class)->forSurface($surface);
$mode = $argv[3];
$type = $mode === 'post' ? 'posts.create' : 'reactions.add';
$namespace = new CommandNamespace($h->actor->installation, $surface->panel, 'host.client', $type);
$target = new TargetRef(new HostEntityRef('sockit.post', $argv[2]), new Incarnation(1));
if ($mode === 'post') {
    $api = $app->make(Posts::class);
    $input = new PostCommand($surface, 'Race post', AudienceSpec::group(new GroupRef($argv[1])), publish: true);
    $command = new CommandEnvelope(new CommandKey('p57.contender.post.0001'), $namespace, $api->digest($h, $input));
} elseif ($mode === 'reaction') {
    $api = $app->make(Reactions::class);
    $input = new ReactionCommand($surface, $target, 'like');
    $command = new CommandEnvelope(new CommandKey('p57.contender.reaction.0001'), $namespace, $api->digest($h, $input, 'add'));
} else {
    throw new RuntimeException('Invalid race mode');
}
$pid = $connection->scalar('SELECT pg_backend_pid()');
if (! is_int($pid)) {
    throw new RuntimeException('Invalid backend PID');
}
fwrite(STDOUT, $pid.PHP_EOL);
fflush(STDOUT);
if ($api instanceof Posts) {
    $result = $api->create($h, $input, $command)->operation;
} else {
    $result = $api->add($h, $input, $command);
}
echo json_encode(['result' => $result->toWire(), 'level' => $connection->transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL;
