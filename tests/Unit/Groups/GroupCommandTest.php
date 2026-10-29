<?php

declare(strict_types=1);

use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;

it('validates typed create and policy inputs without caller authority', function (): void {
    $resource = new ResourceRef(new PanelId('acme.a'), ResourceKind::Group, '019a1ba0-0000-7000-8000-000000000042');
    $create = new GroupCommand(JoinPolicy::Approval, 'Chess');
    $policy = new GroupCommand(JoinPolicy::Closed, resource: $resource);
    expect($create->name)->toBe('Chess')->and($policy->resource)->toBe($resource)
        ->and(array_keys(get_object_vars($create)))->toBe(['joinPolicy', 'name', 'resource']);
    expect(fn () => new GroupCommand(JoinPolicy::Auto, ''))->toThrow(InvalidPayloadException::class);
    expect(fn () => new GroupCommand(JoinPolicy::Auto, str_repeat('x', 513)))->toThrow(InvalidPayloadException::class);
    expect(fn () => new GroupCommand(JoinPolicy::Auto, 'name', $resource))->toThrow(InvalidPayloadException::class);
    expect(fn () => new GroupCommand(JoinPolicy::Auto, resource: new ResourceRef(new PanelId('acme.a'), ResourceKind::Post, '42')))->toThrow(InvalidPayloadException::class);
    expect(fn () => new Revision(0))->toThrow(InvalidPayloadException::class);
});
