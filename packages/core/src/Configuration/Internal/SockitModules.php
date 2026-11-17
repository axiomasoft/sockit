<?php

declare(strict_types=1);

namespace SocKit\Configuration\Internal;

use Packman\Attributes\Internal;
use SocKit\Panels\Exceptions\PanelException;

/**
 * Canonical catalogue and alias normalizer for built-in SocKit optional code modules.
 */
#[Internal]
final class SockitModules
{
    /** @var array<string, string> */
    private const array ALIASES = [
        'reactions' => 'reactions',
        'sockit.reactions' => 'reactions',
        'axiomasoft.sockit.reactions' => 'reactions',
        'posts' => 'posts',
        'sockit.posts' => 'posts',
        'axiomasoft.sockit.posts' => 'posts',
        'groups' => 'groups',
        'sockit.groups' => 'groups',
        'axiomasoft.sockit.groups' => 'groups',
    ];

    /** @var array<string, list<string>> */
    private const array TABLES = [
        'reactions' => ['reactions', 'reaction_counts'],
        'posts' => ['posts', 'post_revisions'],
        'groups' => ['groups', 'group_members'],
    ];

    public static function normalize(string $moduleId): string
    {
        $normalized = self::ALIASES[$moduleId] ?? null;

        if ($normalized === null) {
            throw PanelException::invalidConfig(
                'MODULE_UNKNOWN',
                "Unknown SocKit module identifier [{$moduleId}].",
                ['module' => $moduleId],
                '/modules',
            );
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $moduleIds
     * @return list<string>
     */
    public static function normalizeList(array $moduleIds): array
    {
        $result = [];

        foreach ($moduleIds as $moduleId) {
            $result[] = self::normalize($moduleId);
        }

        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string, list<string>> */
    public static function tablesByModule(): array
    {
        return self::TABLES;
    }

    public static function isKnownFeature(string $feature): bool
    {
        return isset(self::TABLES[$feature]);
    }
}
