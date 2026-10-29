<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\PanelConsumer\SocialConsumer;

it('isolates public reactions on generated Article and Product panels and rolls back only Article', function (bool $separate): void {
    $consumer = new SocialConsumer($separate);
    try {
        $consumer->generate();
        $consumer->migrate();
        $consumer->smoke();
        $consumer->rollback();
    } finally {
        $consumer->cleanup();
    }
})->with(['one database with different prefixes' => false, 'distinct owner connections and databases' => true]);
