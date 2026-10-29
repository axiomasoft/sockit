<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\PanelConsumer\SocialConsumer;

it('generates and boots real production reactions providers custom models and immutable snapshots without overwrite', function (): void {
    $consumer = new SocialConsumer;
    try {
        $consumer->generate();
    } finally {
        $consumer->cleanup();
    }
});
