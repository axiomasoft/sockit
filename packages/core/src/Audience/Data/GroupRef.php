<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use SocKit\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class GroupRef
{
    public function __construct(public string $value)
    {
        if ($value === '' || strlen($value) > 64 || preg_match('~[\x00-\x1f\x7f]~', $value)) {
            throw new InvalidPayloadException('Invalid group reference');
        }
    }
}
