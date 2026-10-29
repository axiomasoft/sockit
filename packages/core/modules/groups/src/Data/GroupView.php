<?php

declare(strict_types=1);

namespace SocKit\Groups\Data;

use SocKit\Attributes\Api;
use SocKit\Audience\Data\GroupRef;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;

#[Api(since: '1.0')]
final readonly class GroupView
{
    public function __construct(public ResourceRef $resource, public GroupRef $group, public string $name, public JoinPolicy $joinPolicy, public Revision $revision) {}
}
