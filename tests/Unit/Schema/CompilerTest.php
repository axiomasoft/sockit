<?php

declare(strict_types=1);
use PHPUnit\Framework\Assert;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Panels\Panel;
use SocKit\Panels\PanelDefinition;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Schema\SchemaCheck;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaForeignKey;
use SocKit\Schema\SchemaIndex;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;
use SocKit\Tests\Fixtures\Schema\DemoTargetsV1;
use SocKit\Tests\Fixtures\Schema\DemoTargetsV2;
use SocKit\Tests\Fixtures\Schema\DependentContribution;
use SocKit\Tests\Fixtures\Schema\InvalidContribution;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('compiles the complete versioned kernel descriptor without loading future models', function (): void {
    $panel = (new Panel)->id('host.articles')->hostTypes('article')->prefix('articles_')->materialize();
    $schema = new PanelSchemaCompiler()->compile(panel: $panel, components: []);
    expect($schema->panel)->toBe('host.articles')
        ->and($schema->storage->tables)->toHaveCount(4)
        ->and($schema->storage->models)->toHaveCount(4)
        ->and($schema->digest)->toHaveLength(64);
});

/** @param list<string> $modules */
function schemaPanel(array $modules = ['acme.demo-targets', 'acme.demo-links'], string $prefix = 'articles_'): PanelDefinition
{
    return (new Panel)->id('host.articles')->hostTypes('article')->prefix($prefix)->modules(...$modules)->materialize();
}

/** @return array<string, SchemaComponent> */
function schemaComponents(): array
{
    return ['acme.demo-targets' => new DemoTargetsV2()->component(), 'acme.demo-links' => new DependentContribution()->component()];
}

it('canonicalizes component/maps permutations and keeps ordered histories and resolved specifications', function (): void {
    $compiler = new PanelSchemaCompiler;
    $components = schemaComponents();
    $a = $compiler->compile(panel: schemaPanel(), components: $components);
    $b = $compiler->compile(panel: schemaPanel(['acme.demo-links', 'acme.demo-targets']), components: array_reverse($components, true));
    expect($a->toJson())->toBe($b->toJson())
        ->and($a->storage->tables['demo_targets'])->toBe('articles_demo_targets')
        ->and($a->storage->tables['demo_links'])->toBe('articles_demo_links')
        ->and($a->storage->models)->toHaveCount(6);
    $wire = $a->jsonSerialize();
    expect($wire['dependency_order'])->toBe(['sockit.kernel', 'acme.demo-targets', 'acme.demo-links']);
    $entities = Scenario::object($wire['entities']);
    $target = Scenario::object($entities['demo_targets']);
    expect(array_keys(Scenario::object($target['columns'])))->toContain('label')
        ->and($target['key_policy'])->toBe('uuid7');
    $receipts = Scenario::object($entities['receipts']);
    $columns = Scenario::object($receipts['columns']);
    expect(Scenario::object($columns['guard_context'])['nullable'])->toBeFalse();
    $tombstones = Scenario::object($entities['tombstones']);
    expect($tombstones['key_policy'])->toBe('composite')
        ->and(Scenario::object($tombstones['model'])['primary'])->toBe(['installation', 'panel', 'kind', 'host_type', 'host_key', 'incarnation']);
    $links = Scenario::object($entities['demo_links']);
    $fk = Scenario::object(Scenario::object($links['foreign_keys'])['target']);
    expect($fk['entity'])->toBe('demo_targets')->and($fk['name'])->toMatch('/^sk_fk_[a-f0-9]{24}$/');
    unset($wire['digest']);
    expect(hash('sha256', SchemaValues::json($wire)))->toBe($a->digest);
});

it('preserves valid custom maps and gives different panel/storage declarations different digests', function (): void {
    $base = schemaPanel();
    $storage = new PanelStorage(connection: 'social', schema: 'social', prefix: 'custom_', tables: ['demo_targets' => 'owned_targets'], models: ['demo_targets' => 'Future\\CustomTarget']);
    $custom = new PanelDefinition(id: $base->id, hostTypes: $base->hostTypes, storage: $storage, modules: $base->modules, moduleSettings: [], floor: $base->floor, provider: '');
    $compiler = new PanelSchemaCompiler;
    $snapshot = $compiler->compile(panel: $custom, components: schemaComponents());
    expect($snapshot->storage->tables['demo_targets'])->toBe('owned_targets')
        ->and($snapshot->storage->tables['receipts'])->toBe('custom_receipts')
        ->and($snapshot->storage->models['demo_targets'])->toBe('Future\\CustomTarget')
        ->and($snapshot->digest)->not->toBe($compiler->compile(panel: $base, components: schemaComponents())->digest);
    $other = (new Panel)->id('host.products')->hostTypes('product')->prefix('articles_')->modules(...$base->modules)->materialize();
    expect($compiler->compile(panel: $other, components: schemaComponents())->digest)->not->toBe($compiler->compile(panel: $base, components: schemaComponents())->digest);
});

it('excludes enabled but not selected fragments and requires dependencies to be explicitly selected', function (): void {
    $compiler = new PanelSchemaCompiler;
    $components = schemaComponents();
    expect($compiler->compile(panel: schemaPanel([]), components: $components)->storage->tables)->toHaveCount(4)
        ->and($compiler->compile(panel: schemaPanel(['acme.demo-targets']), components: $components)->storage->tables)->toHaveCount(5);
    expect(fn () => $compiler->compile(panel: schemaPanel(['acme.demo-links']), components: $components))->toThrow(InvalidSchemaException::class, 'Invalid SocKit schema declaration');
});

it('rejects missing versions, cycles, wrong owners and duplicate entities with problem rules', function (string $case, string $rule): void {
    $step = new SchemaStep(id: 'create_test', version: 1, entity: 'test_entity', columns: ['id' => new SchemaColumn('uuid')], model: new SchemaModel('TestEntity'));
    $components = match ($case) {
        'missing' => ['acme.one' => new SchemaComponent(id: 'acme.one', version: 1, steps: [$step], dependencies: ['acme.missing' => 1])],
        'version' => ['acme.one' => new SchemaComponent(id: 'acme.one', version: 1, steps: [$step], dependencies: ['sockit.kernel' => 2])],
        'cycle' => ['acme.one' => new SchemaComponent(id: 'acme.one', version: 1, steps: [$step], dependencies: ['acme.one' => 1])],
        'owner' => ['acme.wrong' => new SchemaComponent(id: 'acme.one', version: 1, steps: [$step])],
        'reserved' => ['sockit.kernel' => new SchemaComponent(id: 'sockit.kernel', version: 1, steps: [$step])],
        'duplicate' => ['acme.one' => new SchemaComponent(id: 'acme.one', version: 1, steps: [$step]), 'acme.two' => new SchemaComponent(id: 'acme.two', version: 1, steps: [$step])],
        'steal' => ['acme.demo-targets' => new InvalidContribution()->component()],
        default => throw new RuntimeException('Unknown fixture case'),
    };
    try {
        new PanelSchemaCompiler()->validateComponents($components);
        Assert::fail('Invalid component graph accepted');
    } catch (InvalidSchemaException $error) {
        expect($error->problem()->value)->toBe('sockit.configuration_invalid')->and($error->detail()['rule'])->toBe($rule);
    }
})->with([
    ['missing', 'SCHEMA_DEPENDENCY_MISSING'], ['version', 'SCHEMA_DEPENDENCY_MISSING'], ['cycle', 'SCHEMA_DEPENDENCY_CYCLE'],
    ['owner', 'SCHEMA_OWNER_INVALID'], ['reserved', 'SCHEMA_OWNER_DUPLICATE'], ['duplicate', 'SCHEMA_ENTITY_DUPLICATE'], ['steal', 'SCHEMA_ENTITY_DUPLICATE'],
]);

it('rejects unknown overrides at compilation even when storage shape is valid', function (string $map): void {
    $base = schemaPanel([]);
    $storage = new PanelStorage(connection: 'social', schema: null, prefix: '', tables: $map === 'tables' ? ['unknown' => 'unknown_table'] : [], models: $map === 'models' ? ['unknown' => 'Future\\Unknown'] : []);
    $panel = new PanelDefinition(id: $base->id, hostTypes: $base->hostTypes, storage: $storage, modules: [], moduleSettings: [], floor: $base->floor, provider: '');
    expect(fn () => new PanelSchemaCompiler()->compile(panel: $panel, components: schemaComponents()))->toThrow(InvalidSchemaException::class);
})->with(['tables', 'models']);

it('accepts additive v2 and rejects changed, removed or regressed frozen histories', function (): void {
    $compiler = new PanelSchemaCompiler;
    $panel = schemaPanel(['acme.demo-targets']);
    $v1 = new DemoTargetsV1()->component();
    $previous = $compiler->compile(panel: $panel, components: [$v1->id => $v1]);
    $current = $compiler->compile(panel: $panel, components: schemaComponents(), previous: $previous);
    expect($current->digest)->not->toBe($previous->digest);
    $changedStep = new SchemaStep(id: $v1->steps[0]->id, version: 1, entity: 'demo_targets', columns: ['id' => new SchemaColumn('uuid')], model: new SchemaModel('DemoTarget'));
    $changed = new SchemaComponent(id: $v1->id, version: 1, steps: [$changedStep]);
    foreach ([
        fn () => $compiler->compile(panel: $panel, components: [$v1->id => $changed], previous: $previous),
        fn () => $compiler->compile(panel: $panel, components: [$v1->id => $v1], previous: $current),
        fn () => $compiler->compile(panel: schemaPanel([]), components: [], previous: $previous),
        fn () => $compiler->compile(panel: schemaPanel(['acme.demo-targets'], 'other_'), components: schemaComponents(), previous: $previous),
    ] as $invalid) {
        expect($invalid)->toThrow(InvalidSchemaException::class);
    }
});

it('rejects duplicate steps, gaps and version regressions before publication', function (): void {
    $step = new DemoTargetsV1()->component()->steps[0];
    expect(fn () => new SchemaComponent(id: 'acme.demo-targets', version: 1, steps: [$step, $step]))->toThrow(InvalidSchemaException::class)
        ->and(fn () => new SchemaComponent(id: 'acme.demo-targets', version: 2, steps: [$step]))->toThrow(InvalidSchemaException::class);
    $v2 = new DemoTargetsV2()->component()->steps[1];
    expect(fn () => new SchemaComponent(id: 'acme.demo-targets', version: 2, steps: [$v2, $step]))->toThrow(InvalidSchemaException::class);
});

it('detaches nested reference arrays and refuses mutation after freeze', function (): void {
    $id = 'id';
    $cast = 'string';
    $column = new SchemaColumn('uuid');
    $model = new SchemaModel(name: 'Frozen', primary: [&$id], casts: ['id' => &$cast]);
    $step = new SchemaStep(id: 'create_frozen', version: 1, entity: 'frozen', columns: ['id' => &$column], model: $model);
    $component = new SchemaComponent(id: 'acme.frozen', version: 1, steps: [&$step]);
    $snapshot = new PanelSchemaCompiler()->compile(panel: schemaPanel(['acme.frozen']), components: ['acme.frozen' => $component]);
    $before = $snapshot->toJson();
    $id = 'changed';
    $cast = 'integer';
    $column = new SchemaColumn('text');
    $step = new SchemaStep(id: 'changed', version: 1, entity: 'different', columns: ['id' => $column], model: new SchemaModel('Different'));
    $wire = $snapshot->jsonSerialize();
    $wire['entities'] = [];
    expect($snapshot->toJson())->toBe($before)->and($model->primary)->toBe(['id'])->and($model->casts)->toBe(['id' => 'string']);
    expect(function () use ($component): void {
        new ReflectionProperty($component, 'steps')->setValue($component, []);
    })->toThrow(Error::class);
});

it('rejects unresolved FK targets, foreign ownership and unknown index columns', function (string $case): void {
    $first = new DemoTargetsV1()->component();
    $step = match ($case) {
        'foreign_owner' => new SchemaStep(id: 'add_stolen', version: 1, entity: 'demo_targets', columns: ['extra' => new SchemaColumn('text')]),
        'unknown_column' => new SchemaStep(id: 'create_bad', version: 1, entity: 'bad', columns: ['id' => new SchemaColumn('uuid')], model: new SchemaModel('Bad'), indexes: ['missing' => new SchemaIndex(['missing'])]),
        'unknown_target' => new SchemaStep(id: 'create_bad', version: 1, entity: 'bad', columns: ['id' => new SchemaColumn('uuid')], model: new SchemaModel('Bad'), foreignKeys: ['missing' => new SchemaForeignKey(columns: ['id'], entity: 'missing', references: ['id'])]),
        default => throw new RuntimeException('Unknown fixture case'),
    };
    $bad = new SchemaComponent(id: 'acme.other', version: 1, steps: [$step], dependencies: [$first->id => 1]);
    expect(fn () => new PanelSchemaCompiler()->validateComponents([$first->id => $first, $bad->id => $bad]))->toThrow(InvalidSchemaException::class);
})->with(['foreign_owner', 'unknown_column', 'unknown_target']);

it('canonicalizes nested metadata maps while preserving ordered composite columns', function (): void {
    $columns = ['id' => new SchemaColumn('uuid'), 'name' => new SchemaColumn('text')];
    $a = new SchemaStep(id: 'create_targets', version: 1, entity: 'targets', columns: $columns, model: new SchemaModel(name: 'Target', casts: ['id' => 'string', 'name' => 'string']));
    $b = new SchemaStep(id: 'create_targets', version: 1, entity: 'targets', columns: array_reverse($columns, true), model: new SchemaModel(name: 'Target', casts: ['name' => 'string', 'id' => 'string']));
    expect($a->digest())->toBe($b->digest());
    $reordered = new SchemaStep(id: 'create_targets', version: 1, entity: 'targets', columns: $columns, model: new SchemaModel(name: 'Target', primary: ['name', 'id']));
    expect($a->digest())->not->toBe($reordered->digest());
});

it('rejects a step appended to an already frozen version even if a newer version follows', function (): void {
    $compiler = new PanelSchemaCompiler;
    $panel = schemaPanel(['acme.demo-targets']);
    $v1 = new DemoTargetsV1()->component();
    $previous = $compiler->compile(panel: $panel, components: [$v1->id => $v1]);
    $invalid = new SchemaComponent(id: $v1->id, version: 2, steps: [...$v1->steps,
        new SchemaStep(id: 'backdated', version: 1, entity: 'demo_targets', columns: ['backdated' => new SchemaColumn(type: 'text', nullable: true)]),
        new DemoTargetsV2()->component()->steps[1],
    ]);
    expect(fn () => $compiler->compile(panel: $panel, components: [$v1->id => $invalid], previous: $previous))->toThrow(InvalidSchemaException::class);
});

it('rejects table/model collisions and overflow after materializing component defaults', function (): void {
    $base = schemaPanel();
    foreach ([
        new PanelStorage(connection: 'social', schema: null, prefix: 'articles_', tables: ['demo_targets' => 'articles_receipts'], models: []),
        new PanelStorage(connection: 'social', schema: null, prefix: '', tables: [], models: ['demo_targets' => 'Future\\Same', 'demo_links' => 'Future\\same']),
        new PanelStorage(connection: 'social', schema: null, prefix: str_repeat('a', 63), tables: [], models: []),
    ] as $storage) {
        $panel = new PanelDefinition(id: $base->id, hostTypes: $base->hostTypes, storage: $storage, modules: $base->modules, moduleSettings: [], floor: $base->floor, provider: '');
        expect(fn () => new PanelSchemaCompiler()->compile(panel: $panel, components: schemaComponents()))->toThrow(SocKitException::class);
    }
});

it('rejects invalid data-only column defaults and model key specifications', function (): void {
    expect(fn () => new SchemaColumn('raw_sql'))->toThrow(InvalidSchemaException::class)
        ->and(fn () => new SchemaColumn(type: 'string'))->toThrow(InvalidSchemaException::class)
        ->and(fn () => new SchemaColumn(type: 'boolean', default: 'true', hasDefault: true))->toThrow(InvalidSchemaException::class)
        ->and(fn () => new SchemaModel(name: 'Bad', primary: ['id', 'other'], keyType: 'int', incrementing: true))->toThrow(InvalidSchemaException::class);
});

it('freezes bounded kernel check predicates and rejects unknown check columns', function (): void {
    $schema = new PanelSchemaCompiler()->compile(panel: schemaPanel([]), components: []);
    $entities = Scenario::object($schema->jsonSerialize()['entities']);
    $surface = Scenario::object($entities['surface_instances']);
    $checks = Scenario::object($surface['checks']);
    expect(Scenario::object($checks['lifecycle'])['values'])->toBe(['archived', 'deleted', 'live'])
        ->and(Scenario::object($checks['deleted'])['kind'])->toBe('nonnull_iff')
        ->and(Scenario::object($checks['revision_positive'])['name'])->toMatch('/^sk_ck_[a-f0-9]{24}$/');
    $bad = new SchemaComponent(id: 'acme.bad', version: 1, steps: [new SchemaStep(id: 'create_bad', version: 1, entity: 'bad', columns: ['id' => new SchemaColumn('uuid')], model: new SchemaModel('Bad'), checks: ['missing' => new SchemaCheck(kind: 'positive', columns: ['missing'])])]);
    expect(fn () => new PanelSchemaCompiler()->validateComponents([$bad->id => $bad]))->toThrow(InvalidSchemaException::class);
});
