<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Exceptions\InvalidAudienceException;
use Stringable;

/**
 * Reference to a SocKit or host-managed group (`<= 64` bytes).
 */
#[Api(since: '1.0')]
final readonly class GroupRef implements Stringable
{
    public const string PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidAudienceException::invalidSpec(
                'GroupRef must match ^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$.',
                '/group_id',
            );
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
