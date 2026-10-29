<?php

declare(strict_types=1);

namespace SocKit\Tests\Arch\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SocKit\Attributes\Api;
use SocKit\Attributes\Spi;
use Throwable;

/**
 * @phpstan-import-type Declaration from SourceScanner
 *
 * @phpstan-type Violation array{gate: string, file: string, line: int, message: string}
 */
final class Gates
{
    private const array MODULES = [
        'Attributes', 'Support', 'Schema', 'Actors', 'Audience', 'Panels', 'Surfaces',
        'Operations', 'Errors', 'Modules', 'Configuration', 'Testing', 'Internal', 'Facades',
    ];

    private const array FORBIDDEN = [
        'Chatom', 'AzGuard', 'Edufy', 'Social', 'CoreX', 'SocKit\\Comments',
        'SocKit\\Forum', 'SocKit\\ChatomBridge', 'SocKit\\Posts', 'SocKit\\Reactions', 'SocKit\\Groups',
    ];

    /** @param list<string> $files
     * @return list<Violation>
     */
    public static function markers(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            foreach (SourceScanner::scan($file)['types'] as $type) {
                $markers = array_filter($type['attributes'], fn (string $name): bool => in_array(strtolower($name), [strtolower(Api::class), strtolower(Spi::class)], true));
                $expected = self::internal($type['name']) ? 0 : 1;
                if (count($markers) !== $expected) {
                    $violations[] = self::violation('A1', $file, $type['line'], $type['name'].' requires '.$expected.' API/SPI markers');
                }
            }
        }

        return $violations;
    }

    /** @param list<string> $files
     * @return list<Violation>
     */
    public static function dependencies(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            foreach (SourceScanner::scan($file)['references'] as $reference) {
                foreach (self::FORBIDDEN as $prefix) {
                    if (self::within($reference['name'], $prefix) && ! self::ownSocial($file, $reference['namespace'], $reference['name'])) {
                        $violations[] = self::violation('A2', $file, $reference['line'], 'Forbidden dependency '.$reference['name']);
                        break;
                    }
                }
            }
        }

        return $violations;
    }

    /** @param list<string> $files
     * @return list<Violation>
     */
    public static function internals(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            foreach (SourceScanner::scan($file)['references'] as $reference) {
                if (preg_match('/^SocKit\\\\([^\\\\]+)\\\\Internal(?:\\\\|$)/i', $reference['name'], $match) === 1
                    && ! self::within($reference['namespace'], 'SocKit\\'.$match[1])) {
                    $violations[] = self::violation('A3', $file, $reference['line'], 'Foreign Internal dependency '.$reference['name']);
                }
            }
        }

        return $violations;
    }

    /** @param list<string> $files
     * @return list<Violation>
     */
    public static function modules(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            foreach (SourceScanner::scan($file)['types'] as $type) {
                $parts = explode('\\', $type['name']);
                $allowed = $parts[0] === 'SocKit' && (
                    (count($parts) === 2 && in_array($parts[1], ['SocKit', 'SocKitServiceProvider'], true))
                    || (count($parts) >= 3 && in_array($parts[1], self::MODULES, true))
                );
                $social = self::socialRoot($file);
                if ($social !== null) {
                    $allowed = self::within($type['name'], 'SocKit\\'.$social);
                }
                if (! $allowed) {
                    $violations[] = self::violation('A4', $file, $type['line'], 'Unknown core namespace '.$type['name']);
                }
            }
        }

        return $violations;
    }

    /** @param list<string> $files
     * @return list<Violation>
     */
    public static function naming(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            foreach (SourceScanner::scan($file)['types'] as $type) {
                if ($type['kind'] === T_CLASS && preg_match('/(?:Service|Manager|Helper|Util)$/', $type['short']) === 1) {
                    $violations[] = self::violation('A5', $file, $type['line'], 'Forbidden class suffix '.$type['name']);
                }
                if ($type['kind'] === T_CLASS && in_array('Data', explode('\\', $type['namespace']), true) && (! $type['final'] || ! $type['readonly'])) {
                    $violations[] = self::violation('A5', $file, $type['line'], 'Data class must be final readonly: '.$type['name']);
                }
            }
        }

        return $violations;
    }

    /** @param list<string> $files
     * @return list<Violation>
     */
    public static function tenancy(array $files): array
    {
        return self::identifiers($files, 'A6', '/^(tenant_id|tenantId)$/');
    }

    /** @param list<string> $files
     * @return list<Violation>
     */
    public static function editions(array $files): array
    {
        return self::identifiers($files, 'A10', '/(pro|premium|enterprise)_?(edition|enabled)/i');
    }

    /** @param list<string> $files composer.json files, not PHP sources
     * @return list<Violation>
     */
    public static function composerRequire(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            $data = json_decode(SourceScanner::read($file), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! isset($data['require']) || ! is_array($data['require'])) {
                $violations[] = self::violation('A7', $file, 1, 'Missing require object');

                continue;
            }
            foreach (array_keys($data['require']) as $name) {
                if (is_string($name) && preg_match('~^(axiomasoft|edufy|corex)/~i', $name) === 1) {
                    $violations[] = self::violation('A7', $file, self::line($file, $name), 'Forbidden core require '.$name);
                }
            }
        }

        return $violations;
    }

    /** @param list<string> $files PHP sources owned by the manifest
     * @return list<Violation>
     */
    public static function ownership(array $files, string $manifest = 'packages/core/composer.json'): array
    {
        $data = json_decode(SourceScanner::read($manifest), true, flags: JSON_THROW_ON_ERROR);
        $owners = is_array($data) && isset($data['extra']) && is_array($data['extra']) ? ($data['extra']['sockit'] ?? null) : null;
        $owners = is_array($owners) ? ($owners['owners'] ?? null) : null;
        $prefixes = [];
        $exclusions = [];
        $paths = [];
        $violations = [];
        if (! is_array($owners) || $owners === []) {
            return [self::violation('A8', $manifest, 1, 'Missing extra.sockit.owners')];
        }
        foreach ($owners as $owner) {
            if (! is_array($owner) || ! isset($owner['namespaces']) || ! is_array($owner['namespaces']) || $owner['namespaces'] === []) {
                $violations[] = self::violation('A8', $manifest, 1, 'Owner requires namespace prefixes');

                continue;
            }
            foreach ($owner['namespaces'] as $prefix) {
                if (! is_string($prefix) || $prefix === '' || ! str_ends_with($prefix, '\\')) {
                    $violations[] = self::violation('A8', $manifest, 1, 'Invalid owner namespace prefix');

                    continue;
                }
                foreach ($prefixes as $previous) {
                    if ((str_starts_with($prefix, $previous) && ! in_array($prefix, $exclusions[$previous] ?? [], true))
                        || str_starts_with($previous, $prefix)) {
                        $violations[] = self::violation('A8', $manifest, 1, 'Overlapping owner prefixes '.$previous.' / '.$prefix);
                    }
                }
                $prefixes[] = $prefix;
                $exclusions[$prefix] = isset($owner['exclude-namespaces']) && is_array($owner['exclude-namespaces']) ? $owner['exclude-namespaces'] : [];
                $paths[$prefix] = isset($owner['paths']) && is_array($owner['paths']) ? $owner['paths'] : [];
            }
        }
        foreach ($files as $file) {
            foreach (SourceScanner::scan($file)['types'] as $type) {
                $matches = array_filter($prefixes, fn (string $prefix): bool => str_starts_with($type['name'], $prefix)
                    && ! array_any($exclusions[$prefix], fn (mixed $excluded): bool => is_string($excluded) && str_starts_with($type['name'], $excluded)));
                foreach ($matches as $prefix) {
                    if (str_starts_with($file, 'packages/') && $paths[$prefix] !== [] && ! array_any($paths[$prefix], fn (mixed $path): bool => is_string($path) && str_starts_with($file, $path.'/'))) {
                        $violations[] = self::violation('A8', $file, $type['line'], 'Source outside owner path: '.$type['name']);
                    }
                }
                if (count($matches) !== 1) {
                    $violations[] = self::violation('A8', $file, $type['line'], 'Expected exactly one namespace owner: '.$type['name']);
                }
            }
        }

        return $violations;
    }

    /**
     * Reflect only marked source types. Framework-inherited methods are not
     * SocKit declarations; inherited SocKit methods and trait methods ARE checked.
     *
     * @param  list<string>  $files
     * @return list<Violation>
     */
    public static function signatures(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            foreach (SourceScanner::scan($file)['types'] as $type) {
                if (! self::marked($type)) {
                    continue;
                }
                try {
                    if (! class_exists($type['name']) && ! interface_exists($type['name']) && ! trait_exists($type['name'])) {
                        require_once $file;
                    }
                    /** @var class-string $name */
                    $name = $type['name'];
                    $reflection = new ReflectionClass($name);
                    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                        if (! str_starts_with($method->getDeclaringClass()->getName(), 'SocKit\\')) {
                            continue;
                        }
                        $types = [$method->getReturnType()];
                        foreach ($method->getParameters() as $parameter) {
                            $types[] = $parameter->getType();
                        }
                        foreach ($types as $signature) {
                            foreach (self::typeNames($signature) as $typeName) {
                                // A trait has no parent until composed into a class. Only
                                // defer that relative type; check all other types here.
                                // Composed methods are checked in their declaring class.
                                if ($reflection->isTrait() && strtolower($typeName) === 'parent') {
                                    continue;
                                }
                                $typeName = self::resolveSignatureType($typeName, $method, $reflection->getName());
                                if (self::forbiddenSignature($typeName)) {
                                    $violations[] = self::violation('A9', $file, $method->getStartLine() ?: $type['line'], $type['name'].'::'.$method->getName().' exposes '.$typeName);
                                }
                            }
                        }
                    }
                } catch (Throwable $error) {
                    $violations[] = self::violation('A9', $file, $type['line'], 'Cannot reflect '.$type['name'].': '.$error->getMessage());
                }
            }
        }

        return $violations;
    }

    /** @param Declaration $type */
    private static function marked(array $type): bool
    {
        return array_any($type['attributes'], fn (string $name): bool => in_array(strtolower($name), [strtolower(Api::class), strtolower(Spi::class)], true));
    }

    /** @return list<string> */
    private static function typeNames(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }
        $names = [];
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $part) {
                array_push($names, ...self::typeNames($part));
            }
        }

        return $names;
    }

    private static function resolveSignatureType(string $name, ReflectionMethod $method, string $publicClass): string
    {
        // self/parent use the lexical declaring class, including inherited methods.
        // static uses late binding to the public type currently being inspected.
        return match (strtolower($name)) {
            'self' => $method->getDeclaringClass()->getName(),
            'parent' => ($method->getDeclaringClass()->getParentClass()
                ?: throw new LogicException('Cannot resolve parent of '.$method->getDeclaringClass()->getName()))->getName(),
            'static' => $publicClass,
            default => $name,
        };
    }

    private static function forbiddenSignature(string $name): bool
    {
        foreach (['Closure', 'Illuminate\\Database\\Eloquent', Request::class, Response::class, \Symfony\Component\HttpFoundation\Request::class, \Symfony\Component\HttpFoundation\Response::class] as $prefix) {
            if (self::within($name, $prefix)) {
                return true;
            }
        }
        if (str_starts_with($name, 'SocKit\\') && self::internal($name)) {
            return true;
        }
        // A consumer's subclass must not hide an Eloquent or HTTP base type.
        if (class_exists($name) || interface_exists($name)) {
            foreach ([Model::class, Builder::class, \Symfony\Component\HttpFoundation\Request::class, \Symfony\Component\HttpFoundation\Response::class] as $base) {
                if (is_a($name, $base, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function internal(string $name): bool
    {
        return in_array('internal', explode('\\', strtolower($name)), true);
    }

    private static function socialRoot(string $file): ?string
    {
        foreach (['reactions' => 'Reactions', 'posts' => 'Posts', 'groups' => 'Groups'] as $path => $name) {
            if (str_starts_with($file, 'packages/core/modules/'.$path.'/src/')) {
                return $name;
            }
        }

        return null;
    }

    private static function ownSocial(string $file, string $namespace, string $reference): bool
    {
        $root = self::socialRoot($file);

        return $root !== null && self::within($namespace, 'SocKit\\'.$root) && self::within($reference, 'SocKit\\'.$root);
    }

    private static function within(string $name, string $prefix): bool
    {
        return strcasecmp($name, $prefix) === 0 || str_starts_with(strtolower($name), strtolower($prefix).'\\');
    }

    /** @param list<string> $files
     * @return list<Violation>
     */
    private static function identifiers(array $files, string $gate, string $pattern): array
    {
        $violations = [];
        foreach ($files as $file) {
            foreach (SourceScanner::scan($file)['identifiers'] as $identifier) {
                if (preg_match($pattern, $identifier['text']) === 1) {
                    $violations[] = self::violation($gate, $file, $identifier['line'], 'Forbidden identifier '.$identifier['text']);
                }
            }
        }

        return $violations;
    }

    /** @return Violation */
    private static function violation(string $gate, string $file, int $line, string $message): array
    {
        return ['gate' => $gate, 'file' => $file, 'line' => $line, 'message' => $gate.' '.$message];
    }

    private static function line(string $file, string $text): int
    {
        $source = SourceScanner::read($file);
        $offset = strpos($source, $text);

        return $offset === false ? 1 : substr_count(substr($source, 0, $offset), "\n") + 1;
    }
}
