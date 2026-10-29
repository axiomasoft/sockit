<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\PanelConsumer\GroupsConsumer;

it('preserves populated reactions and consumer files through groups upgrade disable quarantine and frozen down', function (): void {
    $f = new GroupsConsumer;
    try {
        $f->prepare();
        $f->upgrade();
        $f->smoke();
        $f->disableAndReenable();
        $f->rollback();
    } finally {
        $f->cleanup();
    }
});
