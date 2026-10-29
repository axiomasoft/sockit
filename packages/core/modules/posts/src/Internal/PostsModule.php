<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class PostsModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(id: 'sockit.posts', databases: ['pgsql']);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->feature('posts')->settings(PostsSettings::class)->schema(InitialSchema::class)
            ->target(alias: 'post', resolver: PostTarget::class)
            ->target(alias: 'sockit.post', resolver: PostTarget::class);
    }
}
