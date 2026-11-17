<?php

declare(strict_types=1);

namespace SocKit\Configuration;

use Closure;
use InvalidArgumentException;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Data\TablePrefix;
use Packman\Config\Laravel\ConfigurationValidator;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Exceptions\InvalidPrivacyFloorException;
use SocKit\Panels\PanelProvider;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\SurfaceProvider;

/**
 * Immutable closed-schema compiled configuration snapshot for `axiomasoft/sockit`.
 */
#[Api(since: '1.0')]
final readonly class SockitOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'ids',
        'panels',
        'surfaces',
        'modules',
        'resolvers',
        'privacy',
        'storage',
        'operations',
    ];

    /** @var list<string> */
    private const array RESOLVER_KEYS = [
        'tenant',
        'actor',
        'contour',
        'audience',
        'authorizer',
        'groups',
        'surface_policy',
        'targets',
    ];

    /**
     * @param  list<class-string<PanelProvider>>  $panelProviders
     * @param  list<class-string<SurfaceProvider>>  $surfaceProviders
     * @param  list<string>  $enabledModules
     * @param  array<string, array<string, scalar|array<array-key, mixed>|null>>  $moduleSettings
     * @param  ?class-string<TenantResolver>  $tenantResolver
     * @param  ?class-string<ActorContextResolver>  $actorResolver
     * @param  ?class-string<ContourResolver>  $contourResolver
     * @param  ?class-string<AudienceResolver>  $audienceResolver
     * @param  ?class-string<ActionAuthorizer>  $authorizerResolver
     * @param  ?class-string<GroupMembershipResolver>  $groupResolver
     * @param  ?class-string<SurfaceAudiencePolicy>  $surfacePolicyResolver
     * @param  ?class-string<TargetResolver>  $targetResolver
     */
    public function __construct(
        public string $idStrategy = 'uuid7',
        public array $panelProviders = [],
        public array $surfaceProviders = [],
        public array $enabledModules = [],
        public array $moduleSettings = [],
        public ?string $tenantResolver = null,
        public ?string $actorResolver = null,
        public ?string $contourResolver = null,
        public ?string $audienceResolver = null,
        public ?string $authorizerResolver = null,
        public ?string $groupResolver = null,
        public ?string $surfacePolicyResolver = null,
        public ?string $targetResolver = null,
        public PrivacyFloor $privacyFloor = new PrivacyFloor(
            allowedScopes: [Scope::Direct],
            defaultScope: Scope::Direct,
            maxScope: Scope::Direct,
            minorDefaultScope: Scope::Direct,
            minorMaxScope: Scope::Direct,
            contourLevels: [],
        ),
        public ?string $connection = null,
        public TablePrefix $tablePrefix = new TablePrefix('sk_'),
        public int $receiptTtlSeconds = 86400,
        public int $outboxBatchSize = 100,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'sockit'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $idStrategy = self::readIdStrategy($validator, $path.'.ids', $config['ids'] ?? []);
        $panelProviders = self::readProviderList($validator, $path.'.panels', $config['panels'] ?? [], PanelProvider::class);
        $surfaceProviders = self::readProviderList($validator, $path.'.surfaces', $config['surfaces'] ?? [], SurfaceProvider::class);
        [$enabledModules, $moduleSettings] = self::readModules($validator, $path.'.modules', $config['modules'] ?? []);
        $resolvers = self::readResolvers($validator, $path.'.resolvers', $config['resolvers'] ?? []);
        $privacyFloor = self::readPrivacyFloor($validator, $path.'.privacy', $config['privacy'] ?? []);
        [$connection, $tablePrefix] = self::readStorage($validator, $path.'.storage', $config['storage'] ?? []);
        [$receiptTtlSeconds, $outboxBatchSize] = self::readOperations($validator, $path.'.operations', $config['operations'] ?? []);

        $validator->failIfAny();

        /** @var list<class-string<PanelProvider>> $panelProviders */
        /** @var list<class-string<SurfaceProvider>> $surfaceProviders */
        /** @var ?class-string<TenantResolver> $tenant */
        $tenant = $resolvers['tenant'];
        /** @var ?class-string<ActorContextResolver> $actor */
        $actor = $resolvers['actor'];
        /** @var ?class-string<ContourResolver> $contour */
        $contour = $resolvers['contour'];
        /** @var ?class-string<AudienceResolver> $audience */
        $audience = $resolvers['audience'];
        /** @var ?class-string<ActionAuthorizer> $authorizer */
        $authorizer = $resolvers['authorizer'];
        /** @var ?class-string<GroupMembershipResolver> $groups */
        $groups = $resolvers['groups'];
        /** @var ?class-string<SurfaceAudiencePolicy> $surfacePolicy */
        $surfacePolicy = $resolvers['surface_policy'];
        /** @var ?class-string<TargetResolver> $targets */
        $targets = $resolvers['targets'];

        return new self(
            idStrategy: $idStrategy,
            panelProviders: $panelProviders,
            surfaceProviders: $surfaceProviders,
            enabledModules: $enabledModules,
            moduleSettings: $moduleSettings,
            tenantResolver: $tenant,
            actorResolver: $actor,
            contourResolver: $contour,
            audienceResolver: $audience,
            authorizerResolver: $authorizer,
            groupResolver: $groups,
            surfacePolicyResolver: $surfacePolicy,
            targetResolver: $targets,
            privacyFloor: $privacyFloor,
            connection: $connection,
            tablePrefix: $tablePrefix,
            receiptTtlSeconds: $receiptTtlSeconds,
            outboxBatchSize: $outboxBatchSize,
        );
    }

    private static function readIdStrategy(ConfigurationValidator $validator, string $path, mixed $raw): string
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an array');

            return 'uuid7';
        }

        $validator->rejectUnknownKeys($raw, ['strategy'], $path);
        $strategy = $raw['strategy'] ?? 'uuid7';

        if ($strategy !== 'uuid7') {
            $validator->add($path.'.strategy', 'must be "uuid7"');

            return 'uuid7';
        }

        return 'uuid7';
    }

    /**
     * @param  class-string  $baseClass
     * @return list<string>
     */
    private static function readProviderList(ConfigurationValidator $validator, string $path, mixed $raw, string $baseClass): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $validator->add($path, 'must be a list of class-string<'.$baseClass.'>');

            return [];
        }

        $result = [];
        foreach ($raw as $index => $entry) {
            if (! is_string($entry) || $entry === '' || ! class_exists($entry) || ! is_subclass_of($entry, $baseClass)) {
                $validator->add($path.'.'.$index, 'must be an existing subclass of '.$baseClass);

                continue;
            }

            $result[] = $entry;
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array{0: list<string>, 1: array<string, array<string, scalar|array<array-key, mixed>|null>>}
     */
    private static function readModules(ConfigurationValidator $validator, string $path, mixed $raw): array
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an array');

            return [[], []];
        }

        $validator->rejectUnknownKeys($raw, ['enabled', 'settings'], $path);

        $enabled = [];
        $rawEnabled = $raw['enabled'] ?? [];

        if (! is_array($rawEnabled) || ! array_is_list($rawEnabled)) {
            $validator->add($path.'.enabled', 'must be a list of non-empty module ID strings');
        } else {
            foreach ($rawEnabled as $index => $moduleId) {
                if (! is_string($moduleId) || $moduleId === '') {
                    $validator->add($path.'.enabled.'.$index, 'must be a non-empty string');

                    continue;
                }

                $enabled[] = $moduleId;
            }
        }

        $settings = [];
        $rawSettings = $raw['settings'] ?? [];

        if (! is_array($rawSettings)) {
            $validator->add($path.'.settings', 'must be an associative map of module ID => settings array');
        } else {
            foreach ($rawSettings as $moduleId => $moduleMap) {
                if (! is_string($moduleId) || $moduleId === '') {
                    $validator->add($path.'.settings', 'module setting keys must be non-empty strings');

                    continue;
                }

                if (! is_array($moduleMap)) {
                    $validator->add($path.'.settings.'.$moduleId, 'must be an array');

                    continue;
                }

                $validatedMap = [];
                foreach ($moduleMap as $key => $val) {
                    if (! is_string($key) || $key === '') {
                        $validator->add($path.'.settings.'.$moduleId, 'setting keys must be non-empty strings');

                        continue;
                    }

                    if (! self::isSerializableConfigValue($val)) {
                        $validator->add($path.'.settings.'.$moduleId.'.'.$key, 'closures and objects are forbidden in configuration');

                        continue;
                    }

                    /** @var scalar|array<array-key, mixed>|null $val */
                    $validatedMap[$key] = $val;
                }

                $settings[$moduleId] = $validatedMap;
            }
        }

        return [array_values(array_unique($enabled)), $settings];
    }

    /** @return array<string, ?string> */
    private static function readResolvers(ConfigurationValidator $validator, string $path, mixed $raw): array
    {
        $contracts = [
            'tenant' => TenantResolver::class,
            'actor' => ActorContextResolver::class,
            'contour' => ContourResolver::class,
            'audience' => AudienceResolver::class,
            'authorizer' => ActionAuthorizer::class,
            'groups' => GroupMembershipResolver::class,
            'surface_policy' => SurfaceAudiencePolicy::class,
            'targets' => TargetResolver::class,
        ];

        $resolved = array_fill_keys(self::RESOLVER_KEYS, null);

        if (! is_array($raw)) {
            $validator->add($path, 'must be an array');

            return $resolved;
        }

        $validator->rejectUnknownKeys($raw, self::RESOLVER_KEYS, $path);

        foreach ($contracts as $key => $contract) {
            $value = $raw[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (! is_string($value) || $value === '' || ! class_exists($value) || ! is_subclass_of($value, $contract)) {
                $validator->add($path.'.'.$key, 'must be null or a class-string implementing '.$contract);

                continue;
            }

            $resolved[$key] = $value;
        }

        return $resolved;
    }

    private static function readPrivacyFloor(ConfigurationValidator $validator, string $path, mixed $raw): PrivacyFloor
    {
        $default = PrivacyFloor::defaultDirect();

        if (! is_array($raw)) {
            $validator->add($path, 'must be an array');

            return $default;
        }

        $validator->rejectUnknownKeys($raw, ['floor'], $path);
        $rawFloor = $raw['floor'] ?? null;

        if ($rawFloor === null) {
            return $default;
        }

        if (! is_array($rawFloor)) {
            $validator->add($path.'.floor', 'must be an array');

            return $default;
        }

        $validator->rejectUnknownKeys($rawFloor, [
            'allowed_scopes',
            'default_scope',
            'max_scope',
            'minor_default_scope',
            'minor_max_scope',
            'contour_levels',
        ], $path.'.floor');

        try {
            return PrivacyFloor::fromWire($rawFloor);
        } catch (InvalidPrivacyFloorException $exception) {
            $validator->add($path.'.floor', $exception->getMessage());

            return $default;
        }
    }

    /** @return array{0: ?string, 1: TablePrefix} */
    private static function readStorage(ConfigurationValidator $validator, string $path, mixed $raw): array
    {
        $prefix = new TablePrefix('sk_');

        if (! is_array($raw)) {
            $validator->add($path, 'must be an array');

            return [null, $prefix];
        }

        $validator->rejectUnknownKeys($raw, ['connection', 'table_prefix'], $path);
        $connection = $raw['connection'] ?? null;

        if ($connection !== null && (! is_string($connection) || trim($connection) === '')) {
            $validator->add($path.'.connection', 'must be null or a non-empty connection name');
            $connection = null;
        }

        try {
            $prefix = TablePrefix::fromArrayValue($raw['table_prefix'] ?? 'sk_', $path.'.table_prefix');
        } catch (InvalidArgumentException $exception) {
            $validator->add($path.'.table_prefix', $exception->getMessage());
        }

        return [$connection, $prefix];
    }

    /** @return array{0: int, 1: int} */
    private static function readOperations(ConfigurationValidator $validator, string $path, mixed $raw): array
    {
        if (! is_array($raw)) {
            $validator->add($path, 'must be an array');

            return [86400, 100];
        }

        $validator->rejectUnknownKeys($raw, ['receipt_ttl_seconds', 'outbox_batch_size'], $path);

        $ttl = $raw['receipt_ttl_seconds'] ?? 86400;
        if (! is_int($ttl) || $ttl < 60 || $ttl > 31536000) {
            $validator->add($path.'.receipt_ttl_seconds', 'must be an integer between 60 and 31536000');
            $ttl = 86400;
        }

        $batch = $raw['outbox_batch_size'] ?? 100;
        if (! is_int($batch) || $batch < 1 || $batch > 5000) {
            $validator->add($path.'.outbox_batch_size', 'must be an integer between 1 and 5000');
            $batch = 100;
        }

        return [$ttl, $batch];
    }

    private static function isSerializableConfigValue(mixed $value): bool
    {
        if ($value instanceof Closure || is_object($value) || is_resource($value)) {
            return false;
        }

        if (! is_array($value)) {
            return is_scalar($value) || $value === null;
        }

        return array_all($value, fn ($nested): bool => self::isSerializableConfigValue($nested));
    }
}
