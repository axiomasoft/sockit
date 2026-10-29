<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\PanelConsumer\CommunityConsumer;

it('composes posts feed and reactions in a generated class community consumer', function (): void {
    $consumer = new CommunityConsumer;
    try {
        $consumer->run();
    } finally {
        $consumer->cleanup();
    }
});
