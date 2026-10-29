<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Internal\Console\DoctorCommand;
use SocKit\SocKitServiceProvider;
use SocKit\Support\Data\InstallationRef;
use SocKit\Testing\FakeTenantResolver;
use SocKit\Testing\SocKitFake;
use SocKit\Tests\Fixtures\PanelConsumer\DoctorAliasPanel;
use SocKit\Tests\Fixtures\PanelConsumer\DoctorProbe;
use SocKit\Tests\Fixtures\Schema\MigrationFixture;
use SocKit\Tests\Fixtures\Schema\MigrationPanel;
use SocKit\Tests\Fixtures\Schema\SchemaProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$options = Scenario::object(json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR));
$fixture = new MigrationFixture(root: $argv[1]);
$fixture->boot(selected: ($options['selected'] ?? true) === true, version: ($options['version'] ?? 1) === 2 ? 2 : 1,
    enableModule: ($options['enableModule'] ?? true) === true);
if (($options['alias'] ?? false) === true) {
    $data = $fixture->app->make('config')->all();
    $config = new Repository($data);
    $config->set('sockit.modules.enabled', ['acme.demo-targets']);
    $config->set('sockit.surfaces', []);
    $config->set('sockit.panels', [MigrationPanel::class, DoctorAliasPanel::class]);
    $config->set('database.connections.owner_alias', $config->get('database.connections.owner_a'));
    $config->set('sockit.storage.deployments.owner_alias', $config->get('sockit.storage.deployments.owner_a'));
    $app = new Illuminate\Foundation\Application($fixture->root);
    $app->instance('env', 'testing');
    $app->instance('config', $config);
    $app->instance(MigrationPanel::class, new MigrationPanel(selected: true));
    $app->instance(ConnectionResolverInterface::class, $fixture->capsule->getDatabaseManager());
    $app->register(SocKitServiceProvider::class);
    $app->register(SchemaProvider::class);
    $app->boot();
    $fixture->app = $app;
}
// Only resolver availability uses test doubles; schema/identity/history are real PostgreSQL.
if (($options['resolvers'] ?? true) === true) {
    SocKitFake::install($fixture->app);
}
$config = $fixture->app->make('config');
foreach (Scenario::object($options['config'] ?? []) as $key => $value) {
    $config->set($key, $value);
}
$connection = $fixture->capsule->getConnection('owner_a');
$data = Scenario::object($connection->getConfig());
$port = $data['port'];
if (! is_int($port) && ! is_string($port)) {
    throw new RuntimeException('Invalid verified port');
}
$probe = new DoctorProbe('pgsql:host='.Scenario::text($data['host']).';port='.(string) $port.';dbname='.Scenario::text($data['database']),
    Scenario::text($data['username']), Scenario::text($data['password']), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$connection->setPdo($probe);
if (isset($options['resolverSql'])) {
    $resolverSql = Scenario::text($options['resolverSql']);
    $fixture->app->forgetInstance(TenantResolver::class);
    $fixture->app->bind(TenantResolver::class, static function () use ($probe, $resolverSql): FakeTenantResolver {
        if ($resolverSql === 'write') {
            $probe->exec("UPDATE public.p36_host SET sentinel='changed'");
        } else {
            $probe->query('SELECT count(*) FROM public.p36_host');
        }

        return new FakeTenantResolver(new InstallationRef('test'));
    });
}

if (($options['driverPrefix'] ?? null) !== null) {
    $connection->setTablePrefix(Scenario::text($options['driverPrefix']));
}
$command = new DoctorCommand;
$command->setLaravel($fixture->app);
$cli = new Application;
$cli->setAutoExit(false);
$cli->setCatchExceptions(false);
$cli->addCommand($command);
$output = new BufferedOutput;
$input = ['command' => 'sockit:doctor', '--json' => ($options['json'] ?? true) === true];
if (($options['panel'] ?? 'acme.article') !== 'omit') {
    $input['--panel'] = $options['panel'] ?? 'acme.article';
}
$exit = $cli->run(new ArrayInput($input), $output);
$raw = $output->fetch();
if (str_contains($raw, Scenario::text($data['password']))) {
    throw new RuntimeException('Doctor leaked credentials');
}
echo json_encode(['exit' => $exit, 'data' => ($input['--json'] ? Scenario::object(json_decode($raw, true, flags: JSON_THROW_ON_ERROR)) : []),
    'output' => $raw, 'sql' => $probe->sql, 'in_transaction' => $probe->inTransaction()], JSON_THROW_ON_ERROR);
