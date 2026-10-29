<?php

declare(strict_types=1);

use Acme\Groups\Host;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Groups;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\SurfaceInstances;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid application');
}
$app->make(Kernel::class)->bootstrap();
$app->instance(Host::class, new Host);
$surface = new SurfaceRef(new PanelId('acme.article'), new SurfaceKindId('acme.management'), new HostEntityRef('article', '42'), new Incarnation(1));
$h = $app->make(OperationHandles::class)->forSurface($surface);
$ns = static fn (string $action): CommandNamespace => new CommandNamespace($h->actor->installation, $surface->panel, 'host.client', $action);
$surfaces = $app->make(SurfaceInstances::class);
$cmd = new CommandEnvelope(new CommandKey('p57.only.surface.0001'), $ns('sockit.surfaces.create'), $surfaces->digest($h, 'create'));
if (! $surfaces->create($h, $cmd)->isSuccess()) {
    throw new RuntimeException('Groups-only surface creation failed');
}
$groups = $app->make(Groups::class);
$input = new GroupCommand(JoinPolicy::Auto, 'Only groups');
$cmd = new CommandEnvelope(new CommandKey('p57.only.create.0001'), $ns('groups.create'), $groups->digest($h, $input));
$result = $groups->create($h, $input, $cmd);
$group = $result->resource ?? throw new RuntimeException('Groups-only create failed');
$input = new MembershipCommand($group, 'join');
$revision = new Revision(1);
$cmd = new CommandEnvelope(new CommandKey('p57.only.join.0001'), $ns('groups.join'), $groups->digest($h, $input, $revision), $revision);
if (! $groups->join($h, $input, $cmd)->isSuccess() || $groups->read($h, $group)->revision->value !== 2) {
    throw new RuntimeException('Groups-only membership failed');
}
foreach (get_declared_classes() as $class) {
    foreach (['SocKit\\Posts\\', 'SocKit\\Reactions\\', 'Chatom\\', 'Forum\\'] as $forbidden) {
        if (str_starts_with($class, $forbidden)) {
            throw new RuntimeException('Unexpected module loaded: '.$class);
        }
    }
}
echo json_encode(['membership' => 'active'], JSON_THROW_ON_ERROR);
