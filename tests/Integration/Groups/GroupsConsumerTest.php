<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\Groups\InstalledConsumer;
use SocKit\Tests\Fixtures\PanelConsumer\GroupsConsumer;

it('uses installed groups on generated panels with current membership and host admission', function (): void {
    $consumer = new GroupsConsumer;
    try {
        $consumer->prepare();
        $consumer->upgrade();
        $consumer->smoke();
    } finally {
        $consumer->cleanup();
    }
});

it('runs an installed groups-only consumer without post or reaction tables', function (): void {
    $consumer = new InstalledConsumer;
    try {
        $consumer->groupsOnly();
    } finally {
        $consumer->cleanup();
    }
});
