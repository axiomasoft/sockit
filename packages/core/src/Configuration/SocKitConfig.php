<?php

declare(strict_types=1);

namespace SocKit\Configuration;

use Illuminate\Contracts\Config\Repository;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Attributes\Api;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Exceptions\InvalidPrivacyFloorException;
use SocKit\Configuration\Data\IdsConfig;
use SocKit\Configuration\Data\ModulesConfig;
use SocKit\Configuration\Data\PanelsConfig;
use SocKit\Configuration\Data\PrivacyConfig;
use SocKit\Configuration\Data\ResolversConfig;
use SocKit\Configuration\Data\SurfacesConfig;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Modules\ModuleManifest;
use SocKit\Panels\PanelProvider;

#[Api(since: '1.0')]
final readonly class SocKitConfig
{
    public function __construct(public IdsConfig $ids, public PanelsConfig $panels, public SurfacesConfig $surfaces, public ModulesConfig $modules, public ResolversConfig $resolvers, public PrivacyConfig $privacy) {}

    public static function fromRepository(Repository $repository): self
    {
        $data = self::normalize($repository);
        $ids = self::map(value: $data['ids'], key: 'sockit.ids');
        $modules = self::map(value: $data['modules'], key: 'sockit.modules');
        $privacy = self::map(value: $data['privacy'], key: 'sockit.privacy');
        self::requireRule(valid: $ids['strategy'] === 'uuid7', key: 'sockit.ids.strategy', rule: 'IDS_STRATEGY');
        $panels = self::providers(value: $data['panels'], key: 'sockit.panels', contract: PanelProvider::class);
        $surfaces = self::providers(value: $data['surfaces'], key: 'sockit.surfaces', contract: 'SocKit\Surfaces\Contracts\SurfaceProvider');
        $enabled = self::strings(value: $modules['enabled'], key: 'sockit.modules.enabled');
        self::requireRule(valid: count(array_unique($enabled)) === count($enabled), key: 'sockit.modules.enabled', rule: 'MODULE_IDS_UNIQUE');
        foreach ($enabled as $id) {
            self::requireRule(valid: preg_match(ModuleManifest::ID_PATTERN, $id) === 1, key: 'sockit.modules.enabled', rule: 'MODULE_INVALID_ID');
        }
        $settings = self::map(value: $modules['settings'], key: 'sockit.modules.settings');
        $moduleSettings = [];
        foreach ($settings as $id => $values) {
            self::requireRule(valid: preg_match(ModuleManifest::ID_PATTERN, $id) === 1, key: 'sockit.modules.settings.'.$id, rule: 'MODULE_INVALID_ID');
            $moduleSettings[$id] = ModulesConfig::settingsMap(value: $values, key: 'sockit.modules.settings.'.$id);
        }
        $resolvers = self::map(value: $data['resolvers'], key: 'sockit.resolvers');
        $classes = [];
        foreach (self::resolverContracts() as $key => $contract) {
            $value = $resolvers[$key];
            if ($value !== null && ! self::implementation(value: $value, contract: $contract)) {
                throw new InvalidConfigurationException(message: 'Invalid resolver implementation', detail: ['key' => 'sockit.resolvers.'.$key, 'rule' => 'RESOLVER_IMPLEMENTATION']);
            }
            $classes[$key] = $value;
        }
        $floor = self::map(value: $privacy['floor'], key: 'sockit.privacy.floor');
        $scopes = self::strings(value: $floor['allowed_scopes'], key: 'sockit.privacy.floor.allowed_scopes');
        $allowed = array_map(fn (string $scope): Scope => self::scope(value: $scope, key: 'allowed_scopes'), $scopes);
        try {
            $privacyFloor = new PrivacyFloor(allowedScopes: $allowed,
                defaultScope: self::scope(value: $floor['default_scope'], key: 'default_scope'),
                maxScope: self::scope(value: $floor['max_scope'], key: 'max_scope'),
                minorDefaultScope: self::scope(value: $floor['minor_default_scope'], key: 'minor_default_scope'),
                minorMaxScope: self::scope(value: $floor['minor_max_scope'], key: 'minor_max_scope'),
                contourLevels: self::strings(value: $floor['contour_levels'], key: 'sockit.privacy.floor.contour_levels'));
        } catch (InvalidPrivacyFloorException $error) {
            throw new InvalidConfigurationException(message: 'Invalid configured privacy floor', detail: ['key' => 'sockit.privacy.floor', 'rule' => $error->detail()['rule']], previous: $error);
        }

        return new self(ids: new IdsConfig($ids['strategy']), panels: new PanelsConfig($panels), surfaces: new SurfacesConfig($surfaces), modules: new ModulesConfig(enabled: $enabled, settings: $moduleSettings),
            resolvers: new ResolversConfig(tenant: $classes['tenant'], actor: $classes['actor'], contour: $classes['contour'], audience: $classes['audience'], authorizer: $classes['authorizer'], groups: $classes['groups'], surfacePolicy: $classes['surface_policy'], targets: $classes['targets']), privacy: new PrivacyConfig($privacyFloor));
    }

    /** Normalize known configuration maps, preserving defaults for empty map overrides.
     * Settings lists use ModulesConfig::merge separately during graph compilation.
     *
     * @return array<string, mixed> */
    public static function normalize(Repository $repository): array
    {
        $raw = $repository->get('sockit', []);
        ModulesConfig::assertSerializable(value: $raw, key: 'sockit');
        $raw = self::map(value: $raw, key: 'sockit', allowed: ['ids', 'panels', 'surfaces', 'modules', 'resolvers', 'privacy']);
        $defaults = self::map(value: require __DIR__.'/../../config/sockit.php', key: 'sockit');
        $data = array_replace($defaults, $raw);
        foreach (['ids', 'modules', 'resolvers', 'privacy'] as $section) {
            $base = self::map(value: $defaults[$section], key: 'sockit.'.$section);
            $values = self::map(value: array_key_exists($section, $raw) ? $raw[$section] : [], key: 'sockit.'.$section, allowed: array_keys($base));
            $data[$section] = array_replace($base, $values);
        }
        $privacyDefaults = self::map(value: $defaults['privacy'], key: 'sockit.privacy');
        $baseFloor = self::map(value: $privacyDefaults['floor'], key: 'sockit.privacy.floor');
        $privacy = self::map(value: $data['privacy'], key: 'sockit.privacy');
        $floor = self::map(value: $privacy['floor'], key: 'sockit.privacy.floor', allowed: array_keys($baseFloor));
        $data['privacy'] = ['floor' => array_replace($baseFloor, $floor)];

        return $data;
    }

    /** @return array<string, string> */
    public static function resolverContracts(): array
    {
        return ['tenant' => TenantResolver::class, 'actor' => ActorContextResolver::class, 'contour' => ContourResolver::class,
            'audience' => AudienceResolver::class, 'authorizer' => ActionAuthorizer::class, 'groups' => GroupMembershipResolver::class,
            'surface_policy' => SurfaceAudiencePolicy::class, 'targets' => 'SocKit\Surfaces\Contracts\TargetResolver'];
    }

    private static function scope(mixed $value, string $key): Scope
    {
        $scope = is_string($value) ? Scope::tryFrom($value) : null;
        if ($scope === null) {
            throw new InvalidConfigurationException(message: 'Unknown privacy scope', detail: ['key' => 'sockit.privacy.floor.'.$key, 'rule' => 'PRIVACY_SCOPE']);
        }

        return $scope;
    }

    /** @phpstan-assert-if-true class-string $value */
    public static function implementation(mixed $value, string $contract): bool
    {
        return is_string($value) && class_exists($value) && is_subclass_of($value, $contract) && new \ReflectionClass($value)->isInstantiable();
    }

    /** @param list<string>|null $allowed
     * @return array<string, mixed> */
    private static function map(mixed $value, string $key, ?array $allowed = null): array
    {
        self::requireRule(valid: is_array($value) && ($value === [] || ! array_is_list($value)), key: $key, rule: 'CONFIG_MAP');
        $result = [];
        foreach ($value as $name => $item) {
            self::requireRule(valid: is_string($name) && ($allowed === null || in_array($name, $allowed, true)), key: $key.'.'.$name, rule: 'CONFIG_UNKNOWN_KEY');
            $result[$name] = $item;
        }

        return $result;
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $key): array
    {
        self::requireRule(valid: is_array($value) && array_is_list($value), key: $key, rule: 'CONFIG_LIST');
        foreach ($value as $item) {
            self::requireRule(valid: is_string($item), key: $key, rule: 'CONFIG_STRING');
        }

        return $value;
    }

    /** @return list<class-string> */
    private static function providers(mixed $value, string $key, string $contract): array
    {
        $values = self::strings(value: $value, key: $key);
        $providers = [];
        foreach ($values as $provider) {
            if (! self::implementation(value: $provider, contract: $contract) || ! is_subclass_of($provider, $contract)) {
                throw new InvalidConfigurationException(message: 'Invalid provider implementation', detail: ['key' => $key, 'rule' => 'PROVIDER_IMPLEMENTATION']);
            }
            $providers[] = $provider;
        }

        return $providers;
    }

    /** @phpstan-assert true $valid */
    private static function requireRule(bool $valid, string $key, string $rule): void
    {
        if (! $valid) {
            throw new InvalidConfigurationException(message: 'Invalid SocKit configuration', detail: ['key' => $key, 'rule' => $rule]);
        }
    }
}
