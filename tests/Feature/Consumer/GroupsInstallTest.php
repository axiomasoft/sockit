<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\PanelConsumer\GroupsConsumer;

it('installs current groups in an independent consumer and restores generated models across panel contexts', function (): void {
    $f = new GroupsConsumer;
    try {
        $f->prepare();
        $f->upgrade();
        $f->smoke();
        $f->rollback();
    } finally {
        $f->cleanup();
    }
});
