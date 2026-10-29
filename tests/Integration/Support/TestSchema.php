<?php

declare(strict_types=1);

namespace SocKit\Tests\Integration\Support;

use PDO;
use SocKit\Panels\PanelStorage;

final class TestSchema
{
    public static function install(PDO $pdo, string $panel): void
    {
        if (! in_array($panel, ['panel_a', 'panel_b'], true)) {
            throw new \RuntimeException('Unexpected test schema');
        }
        $pdo->exec('CREATE SCHEMA '.$panel);
        foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
            $pdo->exec('CREATE TABLE '.$panel.'.'.$entity.' (id text PRIMARY KEY, payload text NOT NULL)');
        }
        $pdo->exec('CREATE TABLE '.$panel.'.acme_runtime_state (id text PRIMARY KEY, value text NOT NULL)');
        $pdo->exec('CREATE TABLE '.$panel.'.acme_runtime_revisions (id text PRIMARY KEY, revision integer NOT NULL)');
    }

    public static function cleanup(PDO $pdo, string $panel): void
    {
        if (! in_array($panel, ['panel_a', 'panel_b'], true)) {
            throw new \RuntimeException('Unexpected test schema');
        }
        $pdo->exec('DROP SCHEMA IF EXISTS '.$panel.' CASCADE');
    }
}
