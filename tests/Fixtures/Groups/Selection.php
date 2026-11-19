<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use SocKit\Groups\GroupsServiceProvider;
use SocKit\Posts\PostsServiceProvider;
use SocKit\Reactions\ReactionsServiceProvider;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Social\SocialPanel;

final class Selection
{
    public const array PROVIDERS = [SocKitServiceProvider::class, ReactionsServiceProvider::class, PostsServiceProvider::class, GroupsServiceProvider::class];

    /** @param list<class-string> $providers
     * @return list<list<class-string>> */
    public static function orders(array $providers): array
    {
        if ($providers === []) {
            return [[]];
        }
        $orders = [];
        foreach ($providers as $i => $provider) {
            $rest = $providers;
            unset($rest[$i]);
            foreach (self::orders(array_values($rest)) as $order) {
                $orders[] = [$provider, ...$order];
            }
        }

        return $orders;
    }

    /** @param list<string> $enabled
     * @param list<string> $selected
     * @param array<string, mixed> $extra
     * @param list<class-string> $providers */
    public static function app(array $enabled = [], array $selected = [], array $extra = [], array $providers = self::PROVIDERS): Application
    {
        $app = new Application;
        $app->instance('config', new Repository(array_replace_recursive([
            'sockit' => ['panels' => [SocialPanel::class], 'modules' => ['enabled' => $enabled]],
            'social_fixture' => ['selected' => $selected],
            'database' => ['default' => 'unavailable', 'connections' => ['unavailable' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'missing_test']]],
        ], $extra)));
        $app->register(DatabaseServiceProvider::class);
        $app->bind('db.connector.pgsql', static function (): never {
            throw new \RuntimeException('Unexpected boot SQL');
        });
        foreach ($providers as $provider) {
            $app->register($provider);
        }

        return $app;
    }
}
