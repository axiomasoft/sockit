<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Audience\AudienceGate;
use SocKit\Modules\Posts\Data\FeedPageView;
use SocKit\Modules\Posts\Data\GetFeedQuery;

/**
 * Capability contract for keyset-paginated, {@see AudienceGate}-filtered surface feeds.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface FeedService
{
    public function feed(GetFeedQuery $query): FeedPageView;
}
