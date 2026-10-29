<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\PanelConsumer\SocialConsumer;

it('enables disables and reenables production modules additively with frozen history pending outbox and isolated rollback', function (): void {
    $consumer = new SocialConsumer;
    try {
        $consumer->generate();
        $consumer->migrate();
        $consumer->smoke();
        $consumer->upgrade();
        $consumer->rollback();
    } finally {
        $consumer->cleanup();
    }
});
