<?php

declare(strict_types=1);

use Acme\Actor;
use Acme\ArticlePanel;
use Acme\ArticleSurface;
use Acme\AuthorityRouter;
use Acme\Host;
use Acme\ProductPanel;
use Acme\ProductSurface;
use Acme\TargetRouter;
use Acme\Tenant;
use SocKit\Audience\AudienceEvaluator;

if (getenv('SOCKIT_EMPTY') === '1') {
    return [];
}

return ['panels' => [ArticlePanel::class, ProductPanel::class], 'surfaces' => [ArticleSurface::class, ProductSurface::class],
    'modules' => ['enabled' => ['acme.runtime']],
    'resolvers' => ['actor' => Actor::class, 'tenant' => Tenant::class, 'contour' => Host::class,
        'groups' => Host::class, 'surface_policy' => Host::class, 'targets' => getenv('SOCKIT_CONSUMER_NO_TARGET') === '1' ? null : TargetRouter::class,
        'authorizer' => AuthorityRouter::class, 'audience' => AudienceEvaluator::class]];
