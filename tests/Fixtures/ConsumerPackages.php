<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures;

use Illuminate\Filesystem\Filesystem;

final class ConsumerPackages
{
    public static function copy(Filesystem $files, string $project, string $root, bool $social): void
    {
        $files->ensureDirectoryExists($root.'/vendor/axiomasoft');
        foreach (['packman', 'packman-plugins'] as $dependency) {
            $files->copyDirectory($project.'/vendor/axiomasoft/'.$dependency, $root.'/vendor/axiomasoft/'.$dependency);
        }
        foreach ($social ? ['core', 'reactions', 'posts', 'groups'] : ['core'] as $package) {
            $files->copyDirectory($project.'/packages/'.$package, $root.'/packages/'.$package);
        }
    }
}
